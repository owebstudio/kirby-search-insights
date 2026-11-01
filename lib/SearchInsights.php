<?php

namespace Oweb\SearchInsights;

use Closure;
use Countable;
use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Http\Cookie;
use Kirby\Toolkit\Collection;
use Throwable;

/**
 * Tracks the searches of a site. One instance per request,
 * available as `searchInsights()` and `$site->searchInsights()`.
 */
class SearchInsights
{
    public const BOTS = '/bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|httpclient|okhttp|java\/|go-http|axios|node-fetch|scrapy|feed/i';

    protected static self|null $instance = null;

    protected Database|null $database = null;
    protected int $position = 0;
    protected Reports|null $reports = null;

    /**
     * The search tracked in this request
     */
    protected array|null $search = null;

    public function __construct(
        protected App $kirby
    ) {
    }

    public static function instance(): static
    {
        $kirby = App::instance();

        if (static::$instance === null || static::$instance->kirby !== $kirby) {
            static::$instance = new static($kirby);
        }

        return static::$instance;
    }

    /**
     * Data attribute for a result link, so that a click on it can be counted.
     * Empty when click tracking is off or no search was tracked in this request.
     */
    public function attr(ModelWithContent|string $result, int|null $position = null): string
    {
        if ($this->search === null || $this->clicks() === false) {
            return '';
        }

        try {
            $position ??= $this->search['offset'] + ++$this->position;

            $token = Token::sign([
                'd' => $this->search['day'],
                'l' => $this->search['lang'],
                't' => $this->search['term'],
                'n' => $this->search['nonce'],
                'p' => is_string($result) === true ? $result : static::modelId($result),
                'i' => $position
            ], $this->database()->secret());

            return 'data-search-insights="' . htmlspecialchars($token, ENT_QUOTES) . '"';
        } catch (Throwable $e) {
            static::log($e);
            return '';
        }
    }

    /**
     * Counts a click from the script on the results page
     */
    public function click(string $token): bool
    {
        if ($this->clicks() === false) {
            return false;
        }

        try {
            $database = $this->database();
            $data     = Token::verify($token, $database->secret());

            if (
                $data === null ||
                is_string($data['d'] ?? null) === false ||
                is_string($data['l'] ?? null) === false ||
                is_string($data['t'] ?? null) === false ||
                is_string($data['n'] ?? null) === false ||
                is_string($data['p'] ?? null) === false ||
                is_int($data['i'] ?? null) === false
            ) {
                return false;
            }

            // tokens are valid on the day of the search and the day after
            if (in_array($data['d'], [$this->today(), date('Y-m-d', strtotime($this->today() . ' -1 day'))], true) === false) {
                return false;
            }

            if ($database->isIgnored($data['t']) === true) {
                return false;
            }

            return $database->addClick($data['d'], $data['l'], $data['t'], $data['p'], $data['i'], $data['n']);
        } catch (Throwable $e) {
            static::log($e);
            return false;
        }
    }

    public function clicks(): bool
    {
        return $this->option('clicks', false) === true;
    }

    public function database(): Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        $file = $this->option('database');

        if ($file instanceof Closure) {
            $file = $file($this->kirby);
        }

        return $this->database = new Database($file ?? $this->kirby->root('logs') . '/search-insights.sqlite');
    }

    public function kirby(): App
    {
        return $this->kirby;
    }

    public static function log(Throwable $e): void
    {
        error_log('Search Insights: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    /**
     * Stable id for a page or file: its UUID when UUIDs are enabled, otherwise its id
     */
    public static function modelId(ModelWithContent $model): string
    {
        try {
            if ($uuid = $model->uuid()) {
                return $uuid->toString();
            }
        } catch (Throwable) {
        }

        return $model->id();
    }

    /**
     * Normalizes a query as it would be stored, or returns `null` when it would be skipped
     */
    public function normalize(string|null $query): string|null
    {
        if ($query === null) {
            return null;
        }

        $term = Term::normalize($query, (int)$this->option('maxLength', 100));
        $callback = $this->option('normalize');

        if ($callback instanceof Closure) {
            $term = $callback($term);

            if (is_string($term) === false) {
                return null;
            }

            $term = Term::normalize($term, (int)$this->option('maxLength', 100));
        }

        if (mb_strlen($term, 'UTF-8') < max(1, (int)$this->option('minLength', 2))) {
            return null;
        }

        return $term;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->kirby->option('owebstudio.search-insights.' . $key, $default);
    }

    /**
     * Data for the Panel
     */
    public function data(): Reports
    {
        return $this->reports ??= new Reports($this);
    }

    /**
     * Reports of the last 30 days for Kirby's stats field:
     * `reports: site.searchInsights.reports`
     */
    public function reports(): array
    {
        return $this->data()->site();
    }

    /**
     * The search tracked in this request, if any
     */
    public function search(): array|null
    {
        return $this->search;
    }

    /**
     * Reasons not to track the current request
     */
    public function skip(): string|null
    {
        $request = $this->kirby->request();

        if ($request->method() === 'HEAD') {
            return 'head';
        }

        $agent = (string)$request->header('User-Agent');

        if ($agent === '' || preg_match(static::BOTS, $agent) === 1) {
            return 'bot';
        }

        if (
            $this->option('respectDnt', true) === true &&
            ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1')
        ) {
            return 'dnt';
        }

        if (
            $this->option('panelUsers', false) !== true &&
            Cookie::exists('kirby_session') === true &&
            $this->kirby->user() !== null
        ) {
            return 'user';
        }

        // the same search on page 2, 3, …
        $page = $request->get('page') ?? $request->params()->get('page');

        if (is_numeric($page) === true && (int)$page > 1) {
            return 'pagination';
        }

        $consent = $this->option('consent');

        if ($consent instanceof Closure && $consent($this->kirby) !== true) {
            return 'consent';
        }

        return null;
    }

    public function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Counts a search. Call it once in the controller of your search page.
     * `$results` can be a collection (e.g. from `$site->search()`), a number
     * or `null` when the number of results is unknown.
     */
    public function track(mixed $query, mixed $results = null): bool
    {
        // one search per request, e.g. when the automatic mode is on as well;
        // `get('q')` can also be an array (`?q[]=…`)
        if ($this->search !== null || is_string($query) === false || Database::isAvailable() === false) {
            return false;
        }

        try {
            $term = $this->normalize($query);

            if ($term === null || $this->skip() !== null) {
                return false;
            }

            if (in_array($term, $this->ignoredByOption(), true) === true) {
                return false;
            }

            $database = $this->database();

            if ($database->isIgnored($term) === true) {
                return false;
            }

            [$count, $offset] = static::count($results);

            $day  = $this->today();
            $lang = $this->kirby->language()?->code() ?? '';

            $database->prune($day, (int)$this->option('retention', 365));

            $term = $database->addSearch($day, $lang, $term, $count, (int)$this->option('maxTermsPerDay', 1000));

            $this->position = 0;
            $this->search   = [
                'day'    => $day,
                'lang'   => $lang,
                'term'   => $term,
                'nonce'  => bin2hex(random_bytes(8)),
                'offset' => $offset
            ];

            return true;
        } catch (Throwable $e) {
            static::log($e);
            return false;
        }
    }

    /**
     * Automatic mode: tracks the search page from the `page.render:before` hook
     */
    public function trackPage(Page $page, array $data, string $contentType): void
    {
        $auto = $this->option('auto', false);

        if (is_array($auto) === false || $contentType !== 'html') {
            return;
        }

        $template = $auto['template'] ?? 'search';

        if ($page->intendedTemplate()->name() !== $template && $page->template()->name() !== $template) {
            return;
        }

        $query = $this->kirby->request()->get($auto['param'] ?? 'q');

        if (is_string($query) === false) {
            return;
        }

        $this->track($query, $data[$auto['results'] ?? 'results'] ?? null);
    }

    /**
     * @return array{0: int|null, 1: int} number of results and offset of the current page
     */
    protected static function count(mixed $results): array
    {
        if ($results instanceof Collection) {
            if ($pagination = $results->pagination()) {
                return [$pagination->total(), $pagination->offset()];
            }

            return [$results->count(), 0];
        }

        return match (true) {
            is_int($results)           => [max(0, $results), 0],
            is_array($results)         => [count($results), 0],
            $results instanceof Countable => [count($results), 0],
            default                    => [null, 0]
        };
    }

    protected function ignoredByOption(): array
    {
        return array_filter(array_map(
            fn ($term) => is_string($term) === true ? Term::normalize($term) : null,
            (array)$this->option('ignore', [])
        ));
    }
}
