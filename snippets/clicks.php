<?php
/**
 * Loads the click tracking script on the search results page.
 * Renders nothing when click tracking is off or no search was tracked.
 */
$insights = searchInsights();

if ($insights->clicks() === false || $insights->search() === null) {
    return;
}

echo js($kirby->plugin('owebstudio/search-insights')->asset('clicks.js')->url(), [
    'data-url' => url('search-insights/click'),
    'defer'    => true
]);
