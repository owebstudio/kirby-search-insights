<template>
  <div class="k-search-insights-empty">
    <k-icon type="search" />
    <p class="k-search-insights-empty-title">{{ $t("owebstudio.search-insights.empty") }}</p>
    <p>{{ $t("owebstudio.search-insights.empty.text") }}</p>

    <div class="k-search-insights-steps">
      <section>
        <h3>1. {{ $t("owebstudio.search-insights.empty.helper") }}</h3>
        <p>{{ $t("owebstudio.search-insights.empty.helper.text") }}</p>
        <k-code language="php">{{ helper }}</k-code>
      </section>
      <section>
        <h3>2. {{ $t("owebstudio.search-insights.empty.auto") }}</h3>
        <p>{{ $t("owebstudio.search-insights.empty.auto.text") }}</p>
        <k-code language="php">{{ auto }}</k-code>
      </section>
      <section>
        <h3>3. {{ $t("owebstudio.search-insights.empty.clicks") }}</h3>
        <p>{{ $t("owebstudio.search-insights.empty.clicks.text") }}</p>
        <k-code language="php">{{ clicks }}</k-code>
      </section>
    </div>

    <k-button
      :text="$t('owebstudio.search-insights.status')"
      dialog="search-insights/status"
      icon="info"
      size="sm"
      variant="filled"
    />
  </div>
</template>

<script>
export default {
  computed: {
    auto() {
      return `// site/config/config.php
'owebstudio.search-insights' => [
    'auto' => [
        'template' => 'search',
        'param'    => 'q',
        'results'  => 'results',
    ],
],`;
    },
    clicks() {
      return `// site/config/config.php
'owebstudio.search-insights' => [
    'clicks' => true,
],

// site/templates/search.php
<?php foreach ($results as $result): ?>
    <a href="<?= $result->url() ?>" <?= searchInsights()->attr($result) ?>>
        <?= $result->title()->esc() ?>
    </a>
<?php endforeach ?>

<?php snippet('search-insights/clicks') ?>`;
    },
    helper() {
      return `// site/controllers/search.php
return function ($site) {
    $query   = get('q');
    $results = $site->search($query, 'title|text');

    searchInsights()->track($query, $results);

    return compact('query', 'results');
};`;
    }
  }
};
</script>
