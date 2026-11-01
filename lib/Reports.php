<?php

namespace Oweb\SearchInsights;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Toolkit\I18n;
use Kirby\Uuid\Uuid;
use NumberFormatter;
use Throwable;

/**
 * Data for the Panel views, the CSV export and the stats field
 */
class Reports
{
    public const PER_PAGE = 20;

    protected array $models = [];
    protected array|null $candidates = null;

    public function __construct(
        protected SearchInsights $insights
    ) {
    }

    public function chart(Range $range, string|null $lang, string|null $term = null): array
    {
        [$where, $bindings] = $this->where($range, $lang, $term);

        $days = [];

        foreach ($this->db()->query('SELECT day, SUM(searches) AS searches, SUM(zero) AS zero FROM searches WHERE ' . $where . ' GROUP BY day', $bindings) as $row) {
            $days[$row['day']] = $row;
        }

        $buckets = [];

        foreach ($range->buckets() as $bucket) {
            $searches = 0;
            $zero     = 0;

            foreach ($days as $day => $row) {
                if ($day >= $bucket['from'] && $day <= $bucket['to']) {
                    $searches += (int)$row['searches'];
                    $zero     += (int)$row['zero'];
                }
            }

            $buckets[] = [...$bucket, 'searches' => $searches, 'zero' => $zero];
        }

        return $buckets;
    }

    /**
     * Pages that were opened from the search results
     */
    public function clicked(Range $range, string|null $lang, array $options = []): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $rows = $this->db()->query(
            'SELECT page, SUM(clicks) AS clicks, SUM(positions) AS positions, COUNT(DISTINCT lang || \':\' || term) AS queries FROM clicks WHERE ' . $where . ' GROUP BY page',
            $bindings
        );

        $top = [];

        foreach ($this->db()->query('SELECT page, term, lang, SUM(clicks) AS clicks FROM clicks WHERE ' . $where . ' GROUP BY page, term, lang', $bindings) as $row) {
            if (isset($top[$row['page']]) === false || $row['clicks'] > $top[$row['page']]['clicks']) {
                $top[$row['page']] = $row;
            }
        }

        $total = array_sum(array_column($rows, 'clicks'));
        $pages = [];

        foreach ($rows as $row) {
            $pages[] = [
                ...$this->model($row['page']),
                'clicks'   => (int)$row['clicks'],
                'share'    => $total > 0 ? $row['clicks'] / $total : 0,
                'queries'  => (int)$row['queries'],
                'position' => round($row['positions'] / max(1, $row['clicks']), 1),
                'top'      => isset($top[$row['page']]) === true ? [
                    'term' => $top[$row['page']]['term'],
                    'lang' => $top[$row['page']]['lang']
                ] : null
            ];
        }

        if ($filter = $this->filter($options['filter'] ?? null)) {
            $pages = array_values(array_filter(
                $pages,
                fn ($page) => str_contains(mb_strtolower($page['title'] . ' ' . $page['path']), $filter)
            ));
        }

        $sort = in_array($options['sort'] ?? null, ['title', 'clicks', 'queries', 'position'], true) === true ? $options['sort'] : 'clicks';
        $dir  = ($options['dir'] ?? null) === 'asc' ? 1 : -1;

        usort($pages, function ($a, $b) use ($sort, $dir) {
            $result = $sort === 'title' ? strnatcasecmp($a['title'], $b['title']) : $a[$sort] <=> $b[$sort];
            return $result * $dir ?: $b['clicks'] <=> $a['clicks'];
        });

        return $this->paginate($pages, $options);
    }

    /**
     * CSV for the queries, the queries without results or the clicked pages
     */
    public function export(string $kind, Range $range, string|null $lang): array
    {
        $options = ['limit' => 0];
        $rows    = match ($kind) {
            'no-results' => [
                ['query', 'language', 'searches_without_results', 'similar_term', 'first_seen', 'last_searched'],
                ...array_map(fn ($row) => [
                    $row['term'],
                    $row['lang'],
                    $row['zero'],
                    $row['similar']['term'] ?? '',
                    $row['first'],
                    $row['last']
                ], $this->terms($range, $lang, [...$options, 'zero' => true, 'sort' => 'zero'])['rows'])
            ],
            'clicks' => [
                ['page', 'title', 'clicks', 'share', 'queries', 'top_query', 'average_position'],
                ...array_map(fn ($row) => [
                    $row['path'],
                    $row['title'],
                    $row['clicks'],
                    round($row['share'], 4),
                    $row['queries'],
                    $row['top']['term'] ?? '',
                    $row['position']
                ], $this->clicked($range, $lang, $options)['rows'])
            ],
            default => [
                ['query', 'language', 'searches', 'previous_searches', 'searches_without_results', 'average_results', 'clicks', 'click_through_rate'],
                ...array_map(fn ($row) => [
                    $row['term'],
                    $row['lang'],
                    $row['searches'],
                    $row['previous'] ?? 0,
                    $row['zero'],
                    $row['results'] ?? '',
                    $row['clicks'],
                    $row['ctr'] === null ? '' : round($row['ctr'], 4)
                ], $this->terms($range, $lang, $options)['rows'])
            ]
        };

        $csv = implode("\r\n", array_map(
            fn ($row) => implode(',', array_map(static::csvCell(...), $row)),
            $rows
        ));

        return [
            'filename' => 'search-insights-' . ($kind === 'no-results' || $kind === 'clicks' ? $kind : 'queries') . '-' . $range->from . '-' . $range->to . '.csv',
            'csv'      => $csv
        ];
    }

    /**
     * Search terms come from visitors: cells that a spreadsheet
     * would run as a formula get a leading apostrophe
     */
    public static function csvCell(mixed $value): string
    {
        $value = (string)$value;

        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            $value = "'" . $value;
        }

        if (preg_match('/[",\r\n]/', $value) === 1) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    public function hasData(): bool
    {
        return $this->db()->exists() === true && $this->db()->value('SELECT 1 FROM searches LIMIT 1') !== null;
    }

    /**
     * Language codes of a multi-language site, otherwise empty
     */
    public function languages(): array
    {
        $languages = [];

        foreach ($this->kirby()->languages() as $language) {
            $languages[] = ['code' => $language->code(), 'name' => $language->name()];
        }

        return $languages;
    }

    /**
     * Stats, chart and the top lists for the overview tab
     */
    public function overview(Range $range, string|null $lang): array
    {
        return [
            'chart'    => $this->chart($range, $lang),
            'clicked'  => $this->clicked($range, $lang, ['limit' => 6])['rows'],
            'none'     => $this->terms($range, $lang, ['zero' => true, 'sort' => 'zero', 'limit' => 8])['rows'],
            'previous' => $this->totals($range->previous(), $lang),
            'queries'  => $this->terms($range, $lang, ['results' => true, 'limit' => 8])['rows']
        ];
    }

    /**
     * Details of a page for the drawer
     */
    public function page(string $id, Range $range, string|null $lang): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $rows = $this->db()->query(
            'SELECT term, lang, SUM(clicks) AS clicks, SUM(positions) AS positions FROM clicks WHERE ' . $where . ' AND page = :page GROUP BY term, lang ORDER BY clicks DESC, term ASC',
            [...$bindings, 'page' => $id]
        );

        $total = (int)$this->db()->value('SELECT COALESCE(SUM(clicks), 0) FROM clicks WHERE ' . $where, $bindings);
        $clicks = array_sum(array_column($rows, 'clicks'));
        $positions = array_sum(array_column($rows, 'positions'));

        return [
            'page'     => $this->model($id),
            'clicks'   => $clicks,
            'share'    => $total > 0 ? $clicks / $total : 0,
            'position' => round($positions / max(1, $clicks), 1),
            'queries'  => array_map(fn ($row) => [
                'term'     => $row['term'],
                'lang'     => $row['lang'],
                'clicks'   => (int)$row['clicks'],
                'share'    => $clicks > 0 ? $row['clicks'] / $clicks : 0,
                'position' => round($row['positions'] / max(1, $row['clicks']), 1)
            ], $rows)
        ];
    }

    /**
     * Details of a term for the drawer
     */
    public function query(string $term, string $lang, Range $range): array
    {
        [$where, $bindings] = $this->where($range, $lang, $term);

        $totals = $this->db()->query(
            'SELECT COALESCE(SUM(searches), 0) AS searches, COALESCE(SUM(zero), 0) AS zero, COALESCE(SUM(results), 0) AS results, COALESCE(SUM(counted), 0) AS counted, COALESCE(SUM(clicked), 0) AS clicked FROM searches WHERE ' . $where,
            $bindings
        )[0];

        [$previousWhere, $previousBindings] = $this->where($range->previous(), $lang, $term);

        $dates = $this->db()->query(
            'SELECT MIN(day) AS first, MAX(day) AS last, MAX(CASE WHEN zero > 0 THEN day END) AS lastZero FROM searches WHERE term = :term AND lang = :lang',
            ['term' => $term, 'lang' => $lang]
        )[0];

        $pages = $this->db()->query(
            'SELECT page, SUM(clicks) AS clicks, SUM(positions) AS positions FROM clicks WHERE ' . $where . ' GROUP BY page ORDER BY clicks DESC',
            $bindings
        );

        $clicks  = array_sum(array_column($pages, 'clicks'));
        $similar = null;

        if ($totals['zero'] > 0 && $totals['zero'] === $totals['counted']) {
            $similar = $this->similar($term, $range, $lang);
        }

        return [
            'term'     => $term,
            'lang'     => $lang,
            'searches' => (int)$totals['searches'],
            'previous' => (int)$this->db()->value('SELECT COALESCE(SUM(searches), 0) FROM searches WHERE ' . $previousWhere, $previousBindings),
            'zero'     => (int)$totals['zero'],
            'results'  => $totals['counted'] > 0 ? round($totals['results'] / $totals['counted'], 1) : null,
            'ctr'      => $totals['searches'] > 0 && $this->insights->clicks() === true ? $totals['clicked'] / $totals['searches'] : null,
            'clicks'   => $clicks,
            'first'    => $dates['first'],
            'last'     => $dates['last'],
            'lastZero' => $dates['lastZero'],
            'similar'  => $similar,
            'chart'    => $this->chart($range, $lang, $term),
            'pages'    => array_map(fn ($row) => [
                ...$this->model($row['page']),
                'clicks'   => (int)$row['clicks'],
                'share'    => $clicks > 0 ? $row['clicks'] / $clicks : 0,
                'position' => round($row['positions'] / max(1, $row['clicks']), 1)
            ], $pages)
        ];
    }

    /**
     * Reports for Kirby's stats field: `reports: site.searchInsights.reports`
     */
    public function site(): array
    {
        try {
            if ($this->hasData() === false) {
                return [];
            }

            $range    = Range::from('30d', today: $this->insights->today());
            $totals   = $this->totals($range, null);
            $previous = $this->totals($range->previous(), null);
            $top      = $this->terms($range, null, ['results' => true, 'limit' => 1])['rows'][0] ?? null;
            $locale   = $this->kirby()->user()?->language();
            $reports  = [
                [
                    'label' => I18n::translate('owebstudio.search-insights.searches'),
                    'value' => I18n::formatNumber($totals['searches'], $locale),
                    'info'  => $previous['searches'] > 0 ? static::change($totals['searches'], $previous['searches'], $locale) . ' ' . I18n::translate('owebstudio.search-insights.previous') : null,
                    'link'  => '/search-insights'
                ],
                [
                    'label' => I18n::translate('owebstudio.search-insights.noResults'),
                    'value' => $totals['zeroRate'] === null ? '–' : static::percent($totals['zeroRate'], $locale, 1),
                    'info'  => I18n::template('owebstudio.search-insights.terms.count', null, ['count' => I18n::formatNumber($totals['zeroTerms'], $locale)]),
                    'link'  => '/search-insights?tab=no-results',
                    'theme' => $totals['zeroTerms'] > 0 ? 'notice' : null
                ]
            ];

            if ($top !== null) {
                $reports[] = [
                    'label' => I18n::translate('owebstudio.search-insights.top'),
                    'value' => $top['term'] === Database::OTHER ? I18n::translate('owebstudio.search-insights.other') : $top['term'],
                    'info'  => I18n::template('owebstudio.search-insights.searches.count', null, ['count' => I18n::formatNumber($top['searches'], $locale)]),
                    'link'  => '/search-insights?tab=queries'
                ];
            }

            return $reports;
        } catch (Throwable $e) {
            SearchInsights::log($e);
            return [];
        }
    }

    /**
     * The most similar term with results, for a term without results
     */
    public function similar(string $term, Range $range, string|null $lang): array|null
    {
        if ($this->candidates === null) {
            $this->candidates = [];

            $rows = $this->db()->query(
                'SELECT term, SUM(searches) AS searches FROM searches WHERE day >= :since AND results > 0 AND term != :other GROUP BY term ORDER BY searches DESC LIMIT 5000',
                ['since' => date('Y-m-d', strtotime($range->to . ' -365 days')), 'other' => Database::OTHER]
            );

            foreach ($rows as $row) {
                $this->candidates[$row['term']] = (int)$row['searches'];
            }
        }

        $similar = Term::similar($term, $this->candidates);

        if ($similar === null) {
            return null;
        }

        [$where, $bindings] = $this->where($range, $lang, $similar);

        return [
            'term'     => $similar,
            'searches' => (int)$this->db()->value('SELECT COALESCE(SUM(searches), 0) FROM searches WHERE ' . $where, $bindings)
        ];
    }

    /**
     * Tracking setup for the status dialog
     */
    public function status(): array
    {
        $db    = $this->db();
        $auto  = $this->insights->option('auto', false);
        $root  = $this->kirby()->root('index');
        $file  = $db->file();
        $ready = Database::isAvailable();

        return [
            'auto'       => is_array($auto) === true ? [
                'template' => $auto['template'] ?? 'search',
                'param'    => $auto['param'] ?? 'q',
                'results'  => $auto['results'] ?? 'results'
            ] : null,
            'clicks'     => $this->insights->clicks(),
            'consent'    => $this->insights->option('consent') !== null,
            'database'   => str_starts_with($file, $root) === true ? ltrim(substr($file, strlen($root)), '/\\') : $file,
            'driver'     => $ready,
            'ignored'    => $ready === true && $db->exists() === true ? count($db->ignored()) : 0,
            'lastClick'  => $ready === true && $db->exists() === true ? $db->value('SELECT MAX(day) FROM clicks') : null,
            'lastSearch' => $ready === true && $db->exists() === true ? $db->value('SELECT MAX(day) FROM searches') : null,
            'panelUsers' => $this->insights->option('panelUsers', false) === true,
            'respectDnt' => $this->insights->option('respectDnt', true) === true,
            'retention'  => (int)$this->insights->option('retention', 365),
            'size'       => $db->size()
        ];
    }

    /**
     * Terms with their counters, sorted and paginated
     */
    public function terms(Range $range, string|null $lang, array $options = []): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        if ($filter = $this->filter($options['filter'] ?? null)) {
            $where .= " AND term LIKE :filter ESCAPE '\\'";
            $bindings['filter'] = '%' . addcslashes($filter, '%_\\') . '%';
        }

        $zero   = ($options['zero'] ?? false) === true;
        $having = match (true) {
            $zero                                  => 'HAVING SUM(zero) > 0',
            ($options['results'] ?? false) === true => 'HAVING SUM(results) > 0',
            default                                => ''
        };

        $orders = [
            'term'     => 's.term',
            'searches' => 's.searches',
            'zero'     => 's.zero',
            'results'  => 'CASE WHEN s.counted > 0 THEN s.results * 1.0 / s.counted ELSE -1 END',
            'clicks'   => 'clicks',
            // terms without results have no click-through and always come last
            'ctr'      => '(s.counted <= s.zero) ASC, s.clicked * 1.0 / s.searches',
            'first'    => 'f.first',
            'last'     => 's.lastZero'
        ];

        $sort  = array_key_exists((string)($options['sort'] ?? ''), $orders) === true ? $options['sort'] : 'searches';
        $dir   = ($options['dir'] ?? null) === 'asc' ? 'ASC' : 'DESC';
        $limit = (int)($options['limit'] ?? static::PER_PAGE);
        $page  = max(1, (int)($options['page'] ?? 1));

        [$clickWhere] = $this->where($range, $lang);

        $from = '(
            SELECT term, lang, SUM(searches) AS searches, SUM(zero) AS zero, SUM(results) AS results,
                SUM(counted) AS counted, SUM(clicked) AS clicked, MAX(CASE WHEN zero > 0 THEN day END) AS lastZero
            FROM searches WHERE ' . $where . ' GROUP BY term, lang ' . $having . '
        ) s';

        $total = (int)$this->db()->value('SELECT COUNT(*) FROM ' . $from, $bindings);

        $sql = 'SELECT s.*, COALESCE(c.clicks, 0) AS clicks, f.first AS first FROM ' . $from . '
            LEFT JOIN (SELECT term, lang, SUM(clicks) AS clicks FROM clicks WHERE ' . $clickWhere . ' GROUP BY term, lang) c
                ON c.term = s.term AND c.lang = s.lang
            LEFT JOIN (SELECT term, lang, MIN(day) AS first FROM searches GROUP BY term, lang) f
                ON f.term = s.term AND f.lang = s.lang
            ORDER BY ' . $orders[$sort] . ' ' . $dir . ', s.searches DESC, s.term ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit);
        }

        $rows = $this->db()->query($sql, $bindings);

        // previous period and trend only for the rows on this page
        $previous = $this->previous($rows, $range, $lang);
        $trends   = ($options['trend'] ?? false) === true ? $this->trends($rows, $range) : [];
        $clicks   = $this->insights->clicks();

        $rows = array_map(function ($row) use ($range, $lang, $previous, $trends, $zero, $clicks) {
            $key = $row['lang'] . ':' . $row['term'];

            return [
                'term'     => $row['term'],
                'lang'     => $row['lang'],
                'searches' => (int)$row['searches'],
                'previous' => $row['first'] >= $range->from ? null : ($previous[$key] ?? 0),
                'isNew'    => $row['first'] >= $range->from,
                'zero'     => (int)$row['zero'],
                'results'  => $row['counted'] > 0 ? round($row['results'] / $row['counted'], 1) : null,
                'clicks'   => (int)$row['clicks'],
                'ctr'      => $clicks === true && $row['counted'] > $row['zero'] ? $row['clicked'] / $row['searches'] : null,
                'first'    => $row['first'],
                'last'     => $row['lastZero'],
                'trend'    => $trends[$key] ?? null,
                'similar'  => $zero === true && $row['term'] !== Database::OTHER ? $this->similar($row['term'], $range, $lang) : null
            ];
        }, $rows);

        return [
            'rows'       => $rows,
            'pagination' => ['page' => $page, 'limit' => $limit > 0 ? $limit : max(1, $total), 'total' => $total]
        ];
    }

    /**
     * Counters for the stats
     */
    public function totals(Range $range, string|null $lang): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $totals = $this->db()->query(
            'SELECT COALESCE(SUM(searches), 0) AS searches, COALESCE(SUM(zero), 0) AS zero, COALESCE(SUM(counted), 0) AS counted, COALESCE(SUM(clicked), 0) AS clicked, COUNT(DISTINCT term) AS terms FROM searches WHERE ' . $where,
            $bindings
        )[0];

        $langWhere = $lang !== null ? 'WHERE lang = :lang' : '';
        $langBindings = $lang !== null ? ['lang' => $lang] : [];

        $newTerms = (int)$this->db()->value(
            'SELECT COUNT(*) FROM (SELECT term FROM searches ' . $langWhere . ' GROUP BY term HAVING MIN(day) >= :from AND MIN(day) <= :to)',
            [...$langBindings, 'from' => $range->from, 'to' => $range->to]
        );

        $zeroTerms = (int)$this->db()->value(
            'SELECT COUNT(*) FROM (SELECT term FROM searches WHERE ' . $where . ' GROUP BY term, lang HAVING SUM(zero) > 0)',
            $bindings
        );

        $clicks = (int)$this->db()->value('SELECT COALESCE(SUM(clicks), 0) FROM clicks WHERE ' . $where, $bindings);

        return [
            'searches'  => (int)$totals['searches'],
            'terms'     => (int)$totals['terms'],
            'newTerms'  => $newTerms,
            'zero'      => (int)$totals['zero'],
            'zeroRate'  => $totals['counted'] > 0 ? $totals['zero'] / $totals['counted'] : null,
            'zeroTerms' => $zeroTerms,
            'clicked'   => (int)$totals['clicked'],
            'ctr'       => $totals['searches'] > 0 && $this->insights->clicks() === true ? $totals['clicked'] / $totals['searches'] : null,
            'clicks'    => $clicks
        ];
    }

    /**
     * "↑ 12%" or "↓ 3%" compared with the previous period, like in the Panel view
     */
    protected static function change(int $current, int $previous, string|null $locale = null): string
    {
        $change = $current / $previous - 1;

        if (abs($change) < 0.005) {
            return '± ' . static::percent(0, $locale);
        }

        return ($change > 0 ? '↑ ' : '↓ ') . static::percent(abs($change), $locale);
    }

    /**
     * Percentage in the format of the user's language
     */
    protected static function percent(float $value, string|null $locale = null, int $digits = 0): string
    {
        if (class_exists(NumberFormatter::class) === true) {
            $formatter = new NumberFormatter($locale ?? 'en', NumberFormatter::PERCENT);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

            if (($formatted = $formatter->format($value)) !== false) {
                return $formatted;
            }
        }

        return I18n::formatNumber(round($value * 100, $digits), $locale) . '%';
    }

    protected function db(): Database
    {
        return $this->insights->database();
    }

    protected function filter(mixed $filter): string|null
    {
        if (is_string($filter) === false) {
            return null;
        }

        $filter = Term::normalize($filter);

        return $filter === '' ? null : $filter;
    }

    protected function kirby(): App
    {
        return $this->insights->kirby();
    }

    /**
     * Title, path and links of a stored page or file id
     */
    protected function model(string $id): array
    {
        if (isset($this->models[$id]) === true) {
            return $this->models[$id];
        }

        $model = null;

        try {
            $model = match (true) {
                Uuid::is($id) === true => Uuid::from($id)?->model(),
                default                => $this->kirby()->page($id) ?? $this->kirby()->file($id)
            };
        } catch (Throwable) {
        }

        if ($model instanceof ModelWithContent === false) {
            return $this->models[$id] = [
                'id'     => $id,
                'title'  => $id,
                'path'   => $id,
                'icon'   => 'cancel-small',
                'exists' => false,
                'link'   => null,
                'url'    => null
            ];
        }

        $panel = null;

        try {
            $panel = $model->panel()->url(true);
        } catch (Throwable) {
        }

        return $this->models[$id] = [
            'id'     => $id,
            'title'  => $model instanceof File ? $model->filename() : (string)$model->title()->value(),
            'path'   => $model->id(),
            'icon'   => $model instanceof File ? 'file' : 'page',
            'exists' => true,
            'link'   => $panel,
            'url'    => $model->url()
        ];
    }

    protected function paginate(array $rows, array $options): array
    {
        $limit = (int)($options['limit'] ?? static::PER_PAGE);
        $page  = max(1, (int)($options['page'] ?? 1));
        $total = count($rows);

        return [
            'rows'       => $limit > 0 ? array_slice($rows, ($page - 1) * $limit, $limit) : $rows,
            'pagination' => ['page' => $page, 'limit' => $limit > 0 ? $limit : max(1, $total), 'total' => $total]
        ];
    }

    protected function previous(array $rows, Range $range, string|null $lang): array
    {
        if ($rows === []) {
            return [];
        }

        $previous = $range->previous();
        $terms    = array_values(array_unique(array_column($rows, 'term')));
        $marks    = implode(',', array_fill(0, count($terms), '?'));
        $sql      = 'SELECT term, lang, SUM(searches) AS searches FROM searches WHERE day >= ? AND day <= ? AND term IN (' . $marks . ')';
        $bindings = [$previous->from, $previous->to, ...$terms];

        if ($lang !== null) {
            $sql .= ' AND lang = ?';
            $bindings[] = $lang;
        }

        $result = [];

        foreach ($this->db()->query($sql . ' GROUP BY term, lang', $bindings) as $row) {
            $result[$row['lang'] . ':' . $row['term']] = (int)$row['searches'];
        }

        return $result;
    }

    protected function trends(array $rows, Range $range): array
    {
        if ($rows === []) {
            return [];
        }

        $parts    = $range->parts(12);
        $terms    = array_values(array_unique(array_column($rows, 'term')));
        $marks    = implode(',', array_fill(0, count($terms), '?'));
        $trends   = [];

        foreach ($rows as $row) {
            $trends[$row['lang'] . ':' . $row['term']] = array_fill(0, count($parts), 0);
        }

        $data = $this->db()->query(
            'SELECT term, lang, day, searches FROM searches WHERE day >= ? AND day <= ? AND term IN (' . $marks . ')',
            [$range->from, $range->to, ...$terms]
        );

        foreach ($data as $row) {
            $key = $row['lang'] . ':' . $row['term'];

            if (isset($trends[$key]) === false) {
                continue;
            }

            foreach ($parts as $index => $part) {
                if ($row['day'] >= $part['from'] && $row['day'] <= $part['to']) {
                    $trends[$key][$index] += (int)$row['searches'];
                    break;
                }
            }
        }

        return $trends;
    }

    /**
     * SQL condition for a range, a language and a term
     */
    protected function where(Range $range, string|null $lang, string|null $term = null): array
    {
        $where    = 'day >= :from AND day <= :to';
        $bindings = ['from' => $range->from, 'to' => $range->to];

        if ($lang !== null) {
            $where .= ' AND lang = :lang';
            $bindings['lang'] = $lang;
        }

        if ($term !== null) {
            $where .= ' AND term = :term';
            $bindings['term'] = $term;
        }

        return [$where, $bindings];
    }
}
