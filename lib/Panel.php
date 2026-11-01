<?php

namespace Oweb\SearchInsights;

use Kirby\Cms\App;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\HtmlString;
use Kirby\Toolkit\I18n;
use Throwable;

/**
 * Views, dialogs, drawers and buttons of the Panel area
 */
class Panel
{
    public const TABS = ['overview', 'queries', 'no-results', 'clicks'];

    public static function button(): array|null
    {
        if (static::canAccess() === false) {
            return null;
        }

        $badge = null;

        try {
            $insights = SearchInsights::instance();

            if (Database::isAvailable() === true && $insights->data()->hasData() === true) {
                $count = $insights->data()->totals(Range::from('30d', today: $insights->today()), null)['zeroTerms'];
                $badge = $count > 0 ? ['text' => (string)$count, 'theme' => 'notice'] : null;
            }
        } catch (Throwable $e) {
            SearchInsights::log($e);
        }

        return [
            'icon'  => 'chart',
            'text'  => I18n::translate('owebstudio.search-insights.title'),
            'link'  => '/search-insights',
            'badge' => $badge
        ];
    }

    public static function canAccess(): bool
    {
        $kirby = App::instance();

        return $kirby->user() !== null && $kirby->panel()->access()->area('search-insights') === true;
    }

    public static function canManage(): bool
    {
        $user = App::instance()->user();

        if ($user === null || static::canAccess() === false) {
            return false;
        }

        if ($user->isAdmin() === true) {
            return true;
        }

        return $user->role()->permissions()->for('owebstudio.search-insights', 'manage') === true;
    }

    /**
     * Base64url, so that terms and page ids fit into one path segment
     */
    public static function decode(string $key): string
    {
        $value = base64_decode(strtr($key, '-_', '+/'), true);

        if ($value === false) {
            throw new NotFoundException(message: 'Invalid key');
        }

        return $value;
    }

    public static function deleteDialog(): array
    {
        static::guard(manage: true);

        return [
            'component' => 'k-text-dialog',
            'props'     => [
                'text'         => new HtmlString(I18n::translate('owebstudio.search-insights.delete.confirm')),
                'submitButton' => [
                    'icon'  => 'trash',
                    'text'  => I18n::translate('delete'),
                    'theme' => 'negative'
                ]
            ]
        ];
    }

    public static function deleteSubmit(): array
    {
        static::guard(manage: true);

        SearchInsights::instance()->database()->clear();

        return [
            'message' => I18n::translate('owebstudio.search-insights.delete.done')
        ];
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function export(): array
    {
        static::guard();

        $insights = SearchInsights::instance();

        return $insights->data()->export(
            static::param('kind') ?? 'queries',
            static::range(),
            static::language()
        );
    }

    public static function ignoreDialog(string $key): array
    {
        static::guard(manage: true);

        return [
            'component' => 'k-text-dialog',
            'props'     => [
                'text' => new HtmlString(I18n::template('owebstudio.search-insights.ignore.confirm', null, [
                    'term' => Escape::html(static::decode($key))
                ])),
                'submitButton' => [
                    'icon'  => 'hidden',
                    'text'  => I18n::translate('owebstudio.search-insights.ignore'),
                    'theme' => 'negative'
                ]
            ]
        ];
    }

    public static function ignoreSubmit(string $key): array
    {
        static::guard(manage: true);

        $term = static::decode($key);

        SearchInsights::instance()->database()->ignore($term);

        return [
            'event'   => 'search-insights.ignore',
            'message' => I18n::template('owebstudio.search-insights.ignore.done', null, ['term' => $term])
        ];
    }

    public static function ignoredDialog(): array
    {
        static::guard();

        $terms = [];

        foreach (SearchInsights::instance()->database()->ignored() as $term => $created) {
            $terms[] = ['term' => (string)$term, 'created' => $created];
        }

        return [
            'component' => 'k-search-insights-ignored-dialog',
            'props'     => [
                'terms'     => $terms,
                'canManage' => static::canManage(),
                'option'    => array_values(array_filter((array)SearchInsights::instance()->option('ignore', []), 'is_string'))
            ]
        ];
    }

    public static function pageDrawer(string $key): array
    {
        static::guard();

        $data = static::withKeys(SearchInsights::instance()->data()->page(static::decode($key), static::range(), static::language()));

        return [
            'component' => 'k-search-insights-page-drawer',
            'props'     => [
                ...$data,
                'icon'  => $data['page']['icon'],
                'title' => $data['page']['title'],
                'range' => static::range()->toArray()
            ]
        ];
    }

    public static function queryDrawer(string $key): array
    {
        static::guard();

        [$lang, $term] = json_decode(static::decode($key), true) ?? [null, null];

        if (is_string($lang) === false || is_string($term) === false) {
            throw new NotFoundException(message: 'Invalid key');
        }

        $insights = SearchInsights::instance();
        $data     = static::withKeys($insights->data()->query($term, $lang, static::range()));

        return [
            'component' => 'k-search-insights-query-drawer',
            'props'     => [
                ...$data,
                'icon'      => 'search',
                'title'     => $term === Database::OTHER ? I18n::translate('owebstudio.search-insights.other') : $term,
                'canManage' => static::canManage(),
                'languages' => $insights->data()->languages(),
                'range'     => static::range()->toArray(),
                'search'    => static::searchUrl($term, $lang),
                'tracking'  => $insights->clicks()
            ]
        ];
    }

    public static function restore(): bool
    {
        static::guard(manage: true);

        $term = static::param('term');

        if (is_string($term) === false) {
            throw new NotFoundException(message: 'Invalid term');
        }

        SearchInsights::instance()->database()->restore($term);

        return true;
    }

    public static function statusDialog(): array
    {
        static::guard();

        return [
            'component' => 'k-search-insights-status-dialog',
            'props'     => [
                'status' => SearchInsights::instance()->data()->status(),
                'size'   => 'large'
            ]
        ];
    }

    public static function view(): array
    {
        static::guard();

        $insights = SearchInsights::instance();
        $data     = $insights->data();
        $range    = static::range();
        $lang     = static::language();
        $tab      = in_array(static::param('tab'), static::TABS, true) === true ? static::param('tab') : 'overview';

        if ($tab === 'clicks' && $insights->clicks() === false) {
            $tab = 'overview';
        }

        $options = [
            'filter' => static::param('q'),
            'sort'   => static::param('sort'),
            'dir'    => static::param('dir'),
            'page'   => static::param('page')
        ];

        $props = [
            'tab'       => $tab,
            'range'     => $range->toArray(),
            'lang'      => $lang,
            'languages' => $data->languages(),
            'clicks'    => $insights->clicks(),
            'canManage' => static::canManage(),
            'filter'    => is_string($options['filter']) === true ? $options['filter'] : '',
            'sort'      => is_string($options['sort']) === true ? $options['sort'] : null,
            'dir'       => $options['dir'] === 'asc' ? 'asc' : 'desc',
            'today'     => $insights->today(),
            'empty'     => false,
            'error'     => null,
            'data'      => null,
            'summary'   => null,
            'zeroTerms' => 0
        ];

        if (Database::isAvailable() === false) {
            return [...$props, 'error' => I18n::translate('owebstudio.search-insights.error.driver')];
        }

        try {
            if ($data->hasData() === false) {
                return [...$props, 'empty' => true];
            }

            $props['summary']   = $data->totals($range, $lang);
            $props['zeroTerms'] = $props['summary']['zeroTerms'];
            $props['data'] = match ($tab) {
                'queries'    => $data->terms($range, $lang, [...$options, 'trend' => true]),
                'no-results' => $data->terms($range, $lang, [...$options, 'zero' => true, 'sort' => $options['sort'] ?? 'zero']),
                'clicks'     => $data->clicked($range, $lang, $options),
                default      => $data->overview($range, $lang)
            };

            $props['data'] = static::withKeys($props['data']);
        } catch (Throwable $e) {
            SearchInsights::log($e);

            $props['error'] = App::instance()->option('debug') === true ? $e->getMessage() : I18n::translate('owebstudio.search-insights.error');
        }

        return $props;
    }

    protected static function guard(bool $manage = false): void
    {
        if (static::canAccess() === false || ($manage === true && static::canManage() === false)) {
            throw new PermissionException();
        }
    }

    protected static function language(): string|null
    {
        $lang = static::param('lang');
        $kirby = App::instance();

        if (is_string($lang) === true && $kirby->multilang() === true && $kirby->language($lang) !== null) {
            return $lang;
        }

        return null;
    }

    /**
     * A query parameter as string, or `null` when it is missing or an array
     */
    protected static function param(string $key): string|null
    {
        $value = get($key);

        return is_string($value) === true ? $value : null;
    }

    protected static function range(): Range
    {
        return Range::from(static::param('range'), static::param('from'), static::param('to'), SearchInsights::instance()->today());
    }

    /**
     * URL of the site's search page for a term, if the automatic mode knows it
     */
    protected static function searchUrl(string $term, string $lang): string|null
    {
        $kirby = App::instance();
        $auto  = SearchInsights::instance()->option('auto', false);

        if ($term === Database::OTHER || is_array($auto) === false) {
            return null;
        }

        $template = $auto['template'] ?? 'search';
        $page     = $kirby->site()->index()->filter(fn ($page) => $page->intendedTemplate()->name() === $template)->first();

        if ($page === null) {
            return null;
        }

        return $page->url($lang !== '' ? $lang : null) . '?' . http_build_query([$auto['param'] ?? 'q' => $term]);
    }

    /**
     * Adds the drawer keys to the rows
     */
    protected static function withKeys(array $data): array
    {
        $key = function (array $row) {
            if (isset($row['term']) === true) {
                $row['key'] = static::encode(json_encode([$row['lang'], $row['term']]));
            }

            if (isset($row['id'], $row['path']) === true) {
                $row['key'] = static::encode($row['id']);
            }

            if (isset($row['top']['term']) === true) {
                $row['top']['key'] = static::encode(json_encode([$row['top']['lang'], $row['top']['term']]));
            }

            if (isset($row['similar']['term']) === true) {
                $row['similar']['key'] = static::encode(json_encode([$row['lang'], $row['similar']['term']]));
            }

            return $row;
        };

        if (isset($data['similar']['term'], $data['lang']) === true) {
            $data['similar']['key'] = static::encode(json_encode([$data['lang'], $data['similar']['term']]));
        }

        foreach (['rows', 'queries', 'none', 'clicked', 'pages'] as $list) {
            if (isset($data[$list]) === true) {
                $data[$list] = array_map($key, $data[$list]);
            }
        }

        return $data;
    }
}
