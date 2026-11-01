<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\Page;
use Kirby\Http\Response;
use Oweb\SearchInsights\Panel;
use Oweb\SearchInsights\SearchInsights;

load([
    'Oweb\\SearchInsights\\Database'       => 'lib/Database.php',
    'Oweb\\SearchInsights\\Panel'          => 'lib/Panel.php',
    'Oweb\\SearchInsights\\Range'          => 'lib/Range.php',
    'Oweb\\SearchInsights\\Reports'        => 'lib/Reports.php',
    'Oweb\\SearchInsights\\SearchInsights' => 'lib/SearchInsights.php',
    'Oweb\\SearchInsights\\Term'           => 'lib/Term.php',
    'Oweb\\SearchInsights\\Token'          => 'lib/Token.php'
], __DIR__);

if (function_exists('searchInsights') === false) {
    /**
     * Tracks the searches of your site: `searchInsights()->track($query, $results)`
     */
    function searchInsights(): SearchInsights
    {
        return SearchInsights::instance();
    }
}

Kirby::plugin('owebstudio/search-insights', [
    'options' => [
        'auto'           => false,
        'clicks'         => false,
        'consent'        => null,
        'database'       => null,
        'ignore'         => [],
        'maxLength'      => 100,
        'maxTermsPerDay' => 1000,
        'minLength'      => 2,
        'normalize'      => null,
        'panelUsers'     => false,
        'respectDnt'     => true,
        'retention'      => 365
    ],
    'permissions' => [
        'manage' => true
    ],
    'translations' => [
        'de' => require __DIR__ . '/translations/de.php',
        'en' => require __DIR__ . '/translations/en.php',
        'fr' => require __DIR__ . '/translations/fr.php',
        'tr' => require __DIR__ . '/translations/tr.php'
    ],
    'hooks' => [
        'page.render:before' => function (string $contentType, array $data, Page $page) {
            searchInsights()->trackPage($page, $data, $contentType);
            return $data;
        }
    ],
    'routes' => function (Kirby $kirby) {
        if ($kirby->option('owebstudio.search-insights.clicks', false) !== true) {
            return [];
        }

        return [
            [
                'pattern' => 'search-insights/click',
                'method'  => 'POST',
                'action'  => function () {
                    $token = get('token');
                    $saved = is_string($token) === true && searchInsights()->click($token) === true;

                    return new Response('', 'text/plain', $saved === true ? 204 : 400);
                }
            ]
        ];
    },
    'siteMethods' => [
        'searchInsights' => function () {
            return SearchInsights::instance();
        }
    ],
    'snippets' => [
        'search-insights/clicks' => __DIR__ . '/snippets/clicks.php'
    ],
    'areas' => [
        'search-insights' => function () {
            return [
                'label' => t('owebstudio.search-insights.title'),
                'icon'  => 'chart',
                'menu'  => true,
                'link'  => 'search-insights',
                'views' => [
                    [
                        'pattern' => 'search-insights',
                        'action'  => function () {
                            return [
                                'component' => 'k-search-insights-view',
                                'title'     => t('owebstudio.search-insights.title'),
                                'props'     => Panel::view()
                            ];
                        }
                    ]
                ],
                'buttons' => [
                    'searchinsights' => function () {
                        return Panel::button();
                    }
                ],
                'dialogs' => [
                    'search-insights/delete' => [
                        'load'   => fn () => Panel::deleteDialog(),
                        'submit' => fn () => Panel::deleteSubmit()
                    ],
                    'search-insights/ignore/(:any)' => [
                        'load'   => fn (string $key) => Panel::ignoreDialog($key),
                        'submit' => fn (string $key) => Panel::ignoreSubmit($key)
                    ],
                    'search-insights/ignored' => [
                        'load' => fn () => Panel::ignoredDialog()
                    ],
                    'search-insights/status' => [
                        'load' => fn () => Panel::statusDialog()
                    ]
                ],
                'drawers' => [
                    'search-insights/page/(:any)' => [
                        'load' => fn (string $key) => Panel::pageDrawer($key)
                    ],
                    'search-insights/query/(:any)' => [
                        'load' => fn (string $key) => Panel::queryDrawer($key)
                    ]
                ]
            ];
        }
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'search-insights/export',
                'method'  => 'GET',
                'action'  => fn () => Panel::export()
            ],
            [
                'pattern' => 'search-insights/restore',
                'method'  => 'POST',
                'action'  => fn () => Panel::restore()
            ]
        ]
    ]
]);
