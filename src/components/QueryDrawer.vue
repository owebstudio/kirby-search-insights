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

      <k-section :label="$t(range.days > 90 ? 'owebstudio.search-insights.chart.week' : 'owebstudio.search-insights.chart.day')">
        <div class="k-search-insights-card">
          <k-search-insights-chart :data="chart" :height="160" :label="title" :mode="zero === searches ? 'zero' : 'single'" />
        </div>
      </k-section>

      <template v-if="results === 0">
        <k-box :text="noneText" icon="alert" theme="notice" />

        <k-section :label="$t('owebstudio.search-insights.drawer.todo')">
          <k-button-group>
            <k-button
              v-if="similar"
              :text="similar.term"
              icon="angle-right"
              size="sm"
              variant="filled"
              @click="openSimilar"
            />
            <k-button
              v-if="search"
              :link="search"
              :text="$t('owebstudio.search-insights.drawer.search')"
              icon="open"
              size="sm"
              target="_blank"
              variant="filled"
            />
            <k-button
              v-if="canManage && term !== ''"
              :dialog="'search-insights/ignore/' + key"
              :text="$t('owebstudio.search-insights.ignore')"
              icon="hidden"
              size="sm"
              variant="filled"
            />
          </k-button-group>
        </k-section>
      </template>

      <k-section v-else-if="tracking" :label="$t('owebstudio.search-insights.drawer.opened')">
        <k-table
          :columns="columns"
          :empty="$t('owebstudio.search-insights.drawer.noClicks')"
          :index="false"
          :rows="rows"
          @cell="openPage($event.row.key)"
        />
        <k-box
          v-if="ctr !== null && searches >= 10 && ctr < 0.35"
          :text="$t('owebstudio.search-insights.drawer.lowCtr', { rate: percentText(ctr) })"
          class="k-search-insights-hint"
          icon="alert"
          theme="notice"
        />
      </k-section>
    </div>
  </k-drawer>
</template>

<script>
import { date, encode, number, percent, query, rangeQuery } from "../helpers.js";

export default {
  inheritAttrs: false,
  props: {
    breadcrumb: Array,
    canManage: Boolean,
    chart: Array,
    clicks: Number,
    ctr: Number,
    current: Boolean,
    first: String,
    icon: String,
    lang: String,
    languages: Array,
    last: String,
    lastZero: String,
    pages: Array,
    previous: Number,
    range: Object,
    results: Number,
    search: String,
    searches: Number,
    similar: Object,
    term: String,
    title: String,
    tracking: Boolean,
    visible: Boolean,
    zero: Number
  },
  emits: ["crumb"],
  computed: {
    columns() {
      return {
        page: { label: this.$t("owebstudio.search-insights.column.page"), type: "search-insights-page", mobile: true },
        clicks: { label: this.$t("owebstudio.search-insights.column.clicks"), align: "right", width: "1/8", mobile: true },
        share: { label: this.$t("owebstudio.search-insights.column.share"), type: "search-insights-share", width: "1/5" },
        position: { label: this.$t("owebstudio.search-insights.column.position"), align: "right", width: "1/6", mobile: true }
      };
    },
    key() {
      return encode(this.term);
    },
    language() {
      return this.languages?.find((language) => language.code === this.lang)?.name;
    },
    noneText() {
      let text = this.$helper.string.escapeHTML(this.$t("owebstudio.search-insights.drawer.none"));

      if (this.similar) {
        text +=
          " " +
          this.$t("owebstudio.search-insights.drawer.none.similar", {
            term: this.$helper.string.escapeHTML(this.similar.term)
          });
      }

      return this.$h(text);
    },
    options() {
      return [
        ...(this.search ? [{ icon: "open", title: this.$t("owebstudio.search-insights.drawer.search"), link: this.search, target: "_blank" }] : []),
        ...(this.canManage && this.term !== ""
          ? [{ icon: "hidden", title: this.$t("owebstudio.search-insights.ignore"), dialog: "search-insights/ignore/" + this.key }]
          : [])
      ];
    },
    rows() {
      return (this.pages ?? []).map((page) => ({
        key: page.key,
        page,
        clicks: number(page.clicks),
        share: page.share,
        position: number(page.position, 1)
      }));
    },
    stats() {
      const stats = [
        {
          label: this.$t("owebstudio.search-insights.searches"),
          value: number(this.searches),
          info: this.previous
            ? this.$t("owebstudio.search-insights.drawer.previous", { count: number(this.previous) })
            : this.$t("owebstudio.search-insights.drawer.new")
        },
        {
          label: this.$t("owebstudio.search-insights.column.results"),
          value: this.results === null ? "–" : number(this.results, 1),
          info: this.$t(this.results === 0 ? "owebstudio.search-insights.drawer.results.none" : "owebstudio.search-insights.drawer.results.info")
        }
      ];

      if (this.results === 0 || !this.tracking) {
        stats.push({
          label: this.$t("owebstudio.search-insights.column.last"),
          value: date(this.lastZero ?? this.last),
          info: this.$t("owebstudio.search-insights.column.first") + ": " + date(this.first)
        });
      } else {
        stats.push({
          label: this.$t("owebstudio.search-insights.ctr"),
          value: percent(this.ctr),
          info: this.$t("owebstudio.search-insights.count.clicks", { count: number(this.clicks) })
        });
      }

      if (this.language) {
        stats.push({
          label: this.$t("owebstudio.search-insights.drawer.language"),
          value: this.language,
          info: this.lang.toUpperCase()
        });
      }

      return stats;
    }
  },
  methods: {
    drawerQuery() {
      return query({ ...rangeQuery(this.range), lang: this.$panel.view.props.lang });
    },
    openPage(key) {
      if (key) {
        this.$panel.drawer.open("search-insights/page/" + key, { query: this.drawerQuery() });
      }
    },
    openSimilar() {
      this.$panel.drawer.open("search-insights/query/" + this.similar.key, { query: this.drawerQuery() });
    },
    percentText(value) {
      return percent(value, 0);
    }
  }
};
</script>
