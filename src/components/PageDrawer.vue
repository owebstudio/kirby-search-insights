<template>
  <k-drawer
    :breadcrumb="breadcrumb"
    :current="current"
    :icon="icon"
    :options="options"
    :title="title"
    :visible="visible"
    class="k-search-insights-drawer"
    size="large"
    @cancel="$panel.drawer.close()"
    @crumb="$emit('crumb', $event)"
    @submit="$panel.drawer.close()"
  >
    <div class="k-search-insights-stack">
      <k-stats :reports="stats" size="small" />

      <k-section :label="$t('owebstudio.search-insights.drawer.sources')">
        <k-table
          :columns="columns"
          :empty="$t('owebstudio.search-insights.drawer.noClicks')"
          :index="false"
          :rows="rows"
          @cell="openQuery($event.row.key)"
        />
      </k-section>
    </div>
  </k-drawer>
</template>

<script>
import { number, percent, query, rangeQuery } from "../helpers.js";

export default {
  inheritAttrs: false,
  props: {
    breadcrumb: Array,
    clicks: Number,
    current: Boolean,
    icon: String,
    page: Object,
    position: Number,
    queries: Array,
    range: Object,
    share: Number,
    title: String,
    visible: Boolean
  },
  emits: ["crumb"],
  computed: {
    columns() {
      return {
        query: { label: this.$t("owebstudio.search-insights.column.query"), type: "search-insights-query", mobile: true },
        clicks: { label: this.$t("owebstudio.search-insights.column.clicks"), align: "right", width: "1/8", mobile: true },
        share: { label: this.$t("owebstudio.search-insights.column.share"), type: "search-insights-share", width: "1/5" },
        position: { label: this.$t("owebstudio.search-insights.column.position"), align: "right", width: "1/6", mobile: true }
      };
    },
    options() {
      return [
        ...(this.page?.link ? [{ icon: "edit-line", title: this.$t("owebstudio.search-insights.drawer.openPanel"), link: this.page.link }] : []),
        ...(this.page?.url ? [{ icon: "open", title: this.$t("owebstudio.search-insights.drawer.openPage"), link: this.page.url, target: "_blank" }] : [])
      ];
    },
    rows() {
      const languages = this.$panel.view.props.languages ?? [];

      return (this.queries ?? []).map((row) => ({
        key: row.key,
        query: { term: row.term, lang: row.lang, showLang: languages.length > 1 && !this.$panel.view.props.lang },
        clicks: number(row.clicks),
        share: row.share,
        position: number(row.position, 1)
      }));
    },
    stats() {
      return [
        {
          label: this.$t("owebstudio.search-insights.drawer.clicks"),
          value: number(this.clicks),
          info: this.$t("owebstudio.search-insights.drawer.clicks.info", { share: percent(this.share, 1) })
        },
        {
          label: this.$t("owebstudio.search-insights.column.queries"),
          value: number(this.queries?.length ?? 0),
          info: this.$t("owebstudio.search-insights.drawer.queries.info")
        },
        {
          label: this.$t("owebstudio.search-insights.column.position"),
          value: number(this.position, 1),
          info: this.$t("owebstudio.search-insights.drawer.position.info")
        }
      ];
    }
  },
  methods: {
    openQuery(key) {
      if (key) {
        this.$panel.drawer.open("search-insights/query/" + key, {
          query: query({ ...rangeQuery(this.range), lang: this.$panel.view.props.lang })
        });
      }
    }
  }
};
</script>
