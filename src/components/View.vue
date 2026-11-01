<template>
  <k-panel-inside class="k-search-insights-view">
    <k-header>
      {{ $t("owebstudio.search-insights.title") }}

      <template #buttons>
        <k-button-group>
          <k-view-button :options="rangeOptions" :text="rangeLabel" icon="calendar" />
          <k-view-button
            v-if="languages.length > 1"
            :options="langOptions"
            :text="langLabel"
            icon="translate"
          />
          <k-view-button
            :disabled="!data"
            :options="exportOptions"
            :text="$t('owebstudio.search-insights.export')"
            icon="download"
          />
          <k-view-button :options="moreOptions" :title="$t('more')" icon="dots" />
        </k-button-group>
      </template>
    </k-header>

    <k-tabs :tab="tab" :tabs="tabs" theme="notice" />

    <k-box v-if="error" :text="error" icon="alert" theme="negative" />

    <k-search-insights-empty v-else-if="empty" />

    <!-- Overview -->
    <div v-else-if="tab === 'overview'" class="k-search-insights-stack">
      <k-stats :reports="stats" size="large" />

      <k-section :label="chartLabel">
        <template #options>
          <k-button-group layout="collapsed" class="k-search-insights-switch">
            <k-button
              :current="chartMode === 'chart'"
              :title="$t('owebstudio.search-insights.chart')"
              icon="chart"
              size="xs"
              variant="filled"
              @click="setChartMode('chart')"
            />
            <k-button
              :current="chartMode === 'table'"
              :title="$t('owebstudio.search-insights.chart.table')"
              icon="table"
              size="xs"
              variant="filled"
              @click="setChartMode('table')"
            />
          </k-button-group>
        </template>

        <div class="k-search-insights-card">
          <header class="k-search-insights-card-header">
            <p class="k-search-insights-legend">
              <span>
                <i data-series="results" />
                {{ $t("owebstudio.search-insights.chart.results") }} · {{ format(totals.searches - totals.zero) }}
              </span>
              <span>
                <i data-series="zero" />
                {{ $t("owebstudio.search-insights.chart.none") }} · {{ format(totals.zero) }}
              </span>
            </p>
            <p class="k-search-insights-dimmed">{{ period }}</p>
          </header>

          <k-search-insights-chart v-if="chartMode === 'chart'" :data="data.chart" :label="chartLabel" />
          <k-table
            v-else
            :columns="chartColumns"
            :index="false"
            :rows="chartRows"
            class="k-search-insights-chart-table"
          />
        </div>
      </k-section>

      <k-grid variant="columns">
        <k-column width="1/2">
          <k-section :buttons="[showAll('queries')]" :label="$t('owebstudio.search-insights.list.queries')">
            <k-table
              :columns="topColumns"
              :empty="$t('owebstudio.search-insights.nothing')"
              :index="false"
              :rows="topRows"
              @cell="openQuery($event.row.key)"
            />
          </k-section>
        </k-column>
        <k-column width="1/2">
          <k-section :buttons="[showAll('no-results')]" :label="$t('owebstudio.search-insights.list.none')">
            <k-table
              :columns="noneColumns"
              :empty="$t('owebstudio.search-insights.noResults.none')"
              :index="false"
              :rows="noneRows"
              @cell="openQuery($event.row.key)"
            />
          </k-section>
        </k-column>
      </k-grid>

      <k-section v-if="clicks" :buttons="[showAll('clicks')]" :label="$t('owebstudio.search-insights.list.clicked')">
        <k-table
          :columns="pageColumns"
          :empty="$t('owebstudio.search-insights.nothing')"
          :index="false"
          :rows="pageRows"
          @cell="openPage($event.row.key)"
        />
      </k-section>
    </div>

    <!-- Queries, no results and clicked results -->
    <div v-else class="k-search-insights-list">
      <k-box
        v-if="tab === 'no-results' && data.pagination.total > 0"
        :text="noResultsIntro"
        class="k-search-insights-intro"
        icon="info"
        theme="info"
      />

      <div class="k-search-insights-toolbar">
        <k-input
          :placeholder="$t(tab === 'clicks' ? 'owebstudio.search-insights.filter.pages' : 'owebstudio.search-insights.filter.queries')"
          :value="filterInput"
          icon="filter"
          type="search"
          @input="onFilter"
        />
        <p class="k-search-insights-dimmed">{{ listInfo }}</p>
      </div>

      <k-table
        :columns="listColumns"
        :empty="listEmpty"
        :index="(data.pagination.page - 1) * data.pagination.limit + 1"
        :pagination="data.pagination.total > data.pagination.limit ? { ...data.pagination, details: true } : false"
        :rows="listRows"
        @cell="onCell"
        @header="onHeader"
        @paginate="go({ page: $event.page })"
      >
        <template #header="{ column, label }">
          <span :data-sortable="Boolean(column.sort)" class="k-search-insights-th">
            {{ label }}
            <k-icon v-if="column.sort && sortKey === column.sort" :type="sortDir === 'asc' ? 'angle-up' : 'angle-down'" />
          </span>
        </template>
        <template #options="{ row }">
          <k-options-dropdown :options="rowOptions(row)" />
        </template>
      </k-table>
    </div>
  </k-panel-inside>
</template>

<script>
import { change, date, encode, number, percent, query, rangeQuery } from "../helpers.js";

const RANGES = ["7d", "30d", "90d", "12m"];

export default {
  props: {
    canManage: Boolean,
    clicks: Boolean,
    data: Object,
    dir: String,
    empty: Boolean,
    error: String,
    filter: String,
    lang: String,
    languages: {
      type: Array,
      default: () => []
    },
    range: Object,
    sort: String,
    summary: Object,
    tab: String,
    today: String,
    zeroTerms: Number
  },
  data() {
    return {
      chartMode: this.load("chart") ?? "chart",
      filterInput: this.filter ?? ""
    };
  },
  computed: {
    chartColumns() {
      return {
        date: { label: this.$t("owebstudio.search-insights.column.date"), mobile: true },
        searches: { label: this.$t("owebstudio.search-insights.column.searches"), align: "right", mobile: true },
        results: { label: this.$t("owebstudio.search-insights.chart.results"), align: "right" },
        zero: { label: this.$t("owebstudio.search-insights.chart.none"), align: "right", mobile: true },
        rate: { label: this.$t("owebstudio.search-insights.column.rate"), align: "right" }
      };
    },
    chartLabel() {
      return this.$t(this.range.days > 90 ? "owebstudio.search-insights.chart.week" : "owebstudio.search-insights.chart.day");
    },
    chartRows() {
      return [...this.data.chart].reverse().map((item) => ({
        date: item.from === item.to ? date(item.from, { weekday: "short", day: "numeric", month: "short", year: "numeric" }) : this.$t("owebstudio.search-insights.chart.weekOf", { date: date(item.from) }),
        searches: number(item.searches),
        results: number(item.searches - item.zero),
        zero: number(item.zero),
        rate: item.searches ? percent(item.zero / item.searches) : "–"
      }));
    },
    current() {
      return {
        tab: this.tab === "overview" ? null : this.tab,
        ...rangeQuery(this.range),
        lang: this.lang,
        q: this.filter,
        sort: this.sort,
        dir: this.sort ? this.dir : null,
        page: this.data?.pagination?.page > 1 ? this.data.pagination.page : null
      };
    },
    drawerQuery() {
      return query({ ...rangeQuery(this.range), lang: this.lang });
    },
    exportOptions() {
      return ["queries", "no-results", ...(this.clicks ? ["clicks"] : [])].map((kind) => ({
        icon: "file-spreadsheet",
        text: this.$t("owebstudio.search-insights.export." + kind) + " (CSV)",
        click: () => this.exportCsv(kind)
      }));
    },
    langLabel() {
      return this.languages.find((language) => language.code === this.lang)?.name ?? this.$t("owebstudio.search-insights.languages.all");
    },
    langOptions() {
      return [
        {
          text: this.$t("owebstudio.search-insights.languages.all"),
          current: !this.lang,
          click: () => this.go({ lang: null, page: null })
        },
        "-",
        ...this.languages.map((language) => ({
          text: language.name,
          current: this.lang === language.code,
          click: () => this.go({ lang: language.code, page: null })
        }))
      ];
    },
    listColumns() {
      const sortable = (key) => ({ sort: key });

      if (this.tab === "clicks") {
        return {
          page: { label: this.$t("owebstudio.search-insights.column.page"), type: "search-insights-page", mobile: true, ...sortable("title") },
          clicks: { label: this.$t("owebstudio.search-insights.column.clicks"), align: "right", width: "1/10", mobile: true, ...sortable("clicks") },
          share: { label: this.$t("owebstudio.search-insights.column.share"), type: "search-insights-share", width: "1/6" },
          queries: { label: this.$t("owebstudio.search-insights.column.queries"), align: "right", width: "1/10", ...sortable("queries") },
          top: { label: this.$t("owebstudio.search-insights.column.top"), type: "search-insights-query", width: "1/5" },
          position: { label: this.$t("owebstudio.search-insights.column.position"), align: "right", width: "1/8", ...sortable("position") }
        };
      }

      if (this.tab === "no-results") {
        return {
          query: { label: this.$t("owebstudio.search-insights.column.query"), type: "search-insights-query", mobile: true, ...sortable("term") },
          searches: { label: this.$t("owebstudio.search-insights.column.searches"), align: "right", width: "1/10", mobile: true, ...sortable("zero") },
          similar: {
            label: this.$t("owebstudio.search-insights.column.similar"),
            type: "search-insights-similar",
            width: "1/4",
            open: (key) => this.openQuery(key)
          },
          first: { label: this.$t("owebstudio.search-insights.column.first"), width: "1/8", ...sortable("first") },
          last: { label: this.$t("owebstudio.search-insights.column.last"), width: "1/8", ...sortable("last") }
        };
      }

      return {
        query: { label: this.$t("owebstudio.search-insights.column.query"), type: "search-insights-query", mobile: true, ...sortable("term") },
        searches: { label: this.$t("owebstudio.search-insights.column.searches"), align: "right", width: "1/10", mobile: true, ...sortable("searches") },
        change: { label: this.$t("owebstudio.search-insights.column.change"), type: "search-insights-change", align: "right", width: "1/10" },
        results: { label: this.$t("owebstudio.search-insights.column.results"), align: "right", width: "1/10", ...sortable("results") },
        ...(this.clicks
          ? {
              clicks: { label: this.$t("owebstudio.search-insights.column.clicks"), align: "right", width: "1/12", ...sortable("clicks") },
              ctr: { label: this.$t("owebstudio.search-insights.column.ctr"), type: "search-insights-share", width: "1/6", ...sortable("ctr") }
            }
          : {}),
        trend: { label: this.$t("owebstudio.search-insights.column.trend"), type: "search-insights-trend", width: "1/10" }
      };
    },
    listEmpty() {
      if (this.filter) {
        return this.$t("owebstudio.search-insights.nothing.filter");
      }

      return this.$t(this.tab === "no-results" ? "owebstudio.search-insights.noResults.none" : "owebstudio.search-insights.nothing");
    },
    listInfo() {
      const count = number(this.data?.pagination?.total ?? 0);

      if (this.tab === "clicks") {
        return this.$t("owebstudio.search-insights.count.pages", { count, clicks: number(this.totals.clicks) });
      }

      if (this.tab === "no-results") {
        return this.$t("owebstudio.search-insights.count.terms", { count });
      }

      return this.$t("owebstudio.search-insights.count.queries", { count, searches: number(this.totals.searches) });
    },
    listRows() {
      const rows = this.data?.rows ?? [];

      if (this.tab === "clicks") {
        return rows.map((row) => ({
          key: row.key,
          page: row,
          clicks: number(row.clicks),
          share: row.share,
          queries: number(row.queries),
          top: row.top ? this.queryValue(row.top) : null,
          position: number(row.position, 1),
          source: row
        }));
      }

      if (this.tab === "no-results") {
        return rows.map((row) => ({
          key: row.key,
          query: this.queryValue(row, true),
          searches: number(row.zero),
          similar: row.similar,
          first: date(row.first),
          last: date(row.last),
          source: row
        }));
      }

      return rows.map((row) => ({
        key: row.key,
        query: this.queryValue(row),
        searches: number(row.searches),
        change: { current: row.searches, previous: row.previous, isNew: row.isNew },
        results: row.results === null ? "–" : number(row.results, 1),
        clicks: row.results === 0 ? "–" : number(row.clicks),
        ctr: row.ctr,
        trend: row.trend,
        source: row
      }));
    },
    moreOptions() {
      return [
        {
          icon: "info",
          text: this.$t("owebstudio.search-insights.status"),
          dialog: "search-insights/status"
        },
        {
          icon: "hidden",
          text: this.$t("owebstudio.search-insights.ignored"),
          dialog: "search-insights/ignored"
        },
        "-",
        {
          icon: "trash",
          text: this.$t("owebstudio.search-insights.delete"),
          dialog: "search-insights/delete",
          disabled: !this.canManage || this.empty
        }
      ];
    },
    noneColumns() {
      return {
        query: { label: this.$t("owebstudio.search-insights.column.query"), type: "search-insights-query", mobile: true },
        searches: { label: this.$t("owebstudio.search-insights.column.searches"), align: "right", width: "1/5", mobile: true },
        similar: {
          label: this.$t("owebstudio.search-insights.column.similar"),
          type: "search-insights-similar",
          width: "2/5",
          open: (key) => this.openQuery(key)
        }
      };
    },
    noneRows() {
      return (this.data.none ?? []).map((row) => ({
        key: row.key,
        query: this.queryValue(row, true),
        searches: number(row.zero),
        similar: row.similar
      }));
    },
    noResultsIntro() {
      return this.$t("owebstudio.search-insights.noResults.intro", {
        count: number(this.totals.zero),
        rate: percent(this.totals.zeroRate)
      });
    },
    pageColumns() {
      return {
        page: { label: this.$t("owebstudio.search-insights.column.page"), type: "search-insights-page", mobile: true },
        clicks: { label: this.$t("owebstudio.search-insights.column.clicks"), align: "right", width: "1/8", mobile: true },
        share: { label: this.$t("owebstudio.search-insights.column.share"), type: "search-insights-share", width: "1/5" },
        top: { label: this.$t("owebstudio.search-insights.column.top"), type: "search-insights-query", width: "1/4" }
      };
    },
    pageRows() {
      return (this.data.clicked ?? []).map((row) => ({
        key: row.key,
        page: row,
        clicks: number(row.clicks),
        share: row.share,
        top: row.top ? this.queryValue(row.top) : null
      }));
    },
    period() {
      return date(this.range.from) + " – " + date(this.range.to);
    },
    rangeLabel() {
      if (this.range.id === "custom") {
        return this.period;
      }

      return this.$t("owebstudio.search-insights.range." + this.range.id);
    },
    rangeOptions() {
      return [
        ...RANGES.map((id) => ({
          text: this.$t("owebstudio.search-insights.range." + id),
          current: this.range.id === id,
          click: () => this.go({ range: id, from: null, to: null, page: null })
        })),
        "-",
        {
          icon: "calendar",
          text: this.$t("owebstudio.search-insights.range.custom"),
          current: this.range.id === "custom",
          click: () => this.customRange()
        }
      ];
    },
    sortDir() {
      if (this.sort) {
        return this.dir;
      }

      return "desc";
    },
    sortKey() {
      return this.sort ?? { queries: "searches", "no-results": "zero", clicks: "clicks" }[this.tab];
    },
    stats() {
      const totals = this.totals;
      const previous = this.data.previous;
      const reports = [
        {
          icon: "search",
          label: this.$t("owebstudio.search-insights.searches"),
          value: number(totals.searches),
          info: this.versus(change(totals.searches, previous.searches)),
          link: this.url({ tab: "queries" })
        },
        {
          icon: "list-bullet",
          label: this.$t("owebstudio.search-insights.terms"),
          value: number(totals.terms),
          info: this.$t("owebstudio.search-insights.terms.new", { count: number(totals.newTerms) }),
          link: this.url({ tab: "queries" })
        },
        {
          icon: "alert",
          label: this.$t("owebstudio.search-insights.noResults"),
          value: percent(totals.zeroRate),
          info: [this.$t("owebstudio.search-insights.noResults.info", { count: number(totals.zero) }), this.points(totals.zeroRate, previous.zeroRate)].filter(Boolean).join(" · "),
          theme: totals.zeroTerms > 0 ? "notice" : null,
          link: this.url({ tab: "no-results" })
        }
      ];

      if (this.clicks) {
        reports.push({
          icon: "open",
          label: this.$t("owebstudio.search-insights.ctr"),
          value: percent(totals.ctr),
          info: [this.$t("owebstudio.search-insights.ctr.info"), this.points(totals.ctr, previous.ctr)].filter(Boolean).join(" · "),
          link: this.url({ tab: "clicks" })
        });
      }

      return reports;
    },
    tabs() {
      const tabs = ["overview", "queries", "no-results", ...(this.clicks ? ["clicks"] : [])];

      return tabs.map((name) => ({
        name,
        label: this.$t("owebstudio.search-insights.tab." + name),
        link: this.url({ tab: name === "overview" ? null : name }),
        badge: name === "no-results" && this.zeroTerms > 0 ? number(this.zeroTerms) : null
      }));
    },
    topColumns() {
      return {
        query: { label: this.$t("owebstudio.search-insights.column.query"), type: "search-insights-query", mobile: true },
        searches: { label: this.$t("owebstudio.search-insights.column.searches"), align: "right", width: "1/5", mobile: true },
        ...(this.clicks
          ? { ctr: { label: this.$t("owebstudio.search-insights.column.ctr"), type: "search-insights-share", width: "1/3" } }
          : { results: { label: this.$t("owebstudio.search-insights.column.results"), align: "right", width: "1/4" } })
      };
    },
    topRows() {
      return (this.data.queries ?? []).map((row) => ({
        key: row.key,
        query: this.queryValue(row, true),
        searches: number(row.searches),
        ctr: row.ctr,
        results: row.results === null ? "–" : number(row.results, 1)
      }));
    },
    totals() {
      return this.data?.totals ?? this.summary ?? {};
    }
  },
  watch: {
    filter(value) {
      if (document.activeElement?.closest(".k-search-insights-toolbar") === null) {
        this.filterInput = value ?? "";
      }
    }
  },
  created() {
    this.$panel.events.on("search-insights.ignore", this.onIgnore);
  },
  beforeUnmount() {
    this.$panel.events.off("search-insights.ignore", this.onIgnore);
    clearTimeout(this.filterTimeout);
  },
  methods: {
    customRange() {
      const max = this.today;

      this.$panel.dialog.open({
        component: "k-form-dialog",
        props: {
          fields: {
            from: {
              label: this.$t("owebstudio.search-insights.range.from"),
              type: "date",
              time: false,
              required: true,
              max,
              width: "1/2"
            },
            to: {
              label: this.$t("owebstudio.search-insights.range.to"),
              type: "date",
              time: false,
              required: true,
              max,
              width: "1/2"
            }
          },
          value: {
            from: this.range.from,
            to: this.range.to
          },
          submitButton: {
            icon: "check",
            text: this.$t("owebstudio.search-insights.apply")
          }
        },
        on: {
          submit: (value) => {
            this.$panel.dialog.close();
            this.go({
              range: "custom",
              from: String(value.from ?? "").slice(0, 10),
              to: String(value.to ?? "").slice(0, 10),
              page: null
            });
          }
        }
      });
    },
    async exportCsv(kind) {
      const response = await this.$panel.api.get("search-insights/export", {
        kind,
        ...query({ ...rangeQuery(this.range), lang: this.lang })
      });

      const blob = new Blob(["﻿" + response.csv], { type: "text/csv;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");

      link.href = url;
      link.download = response.filename;
      document.body.append(link);
      link.click();
      link.remove();

      setTimeout(() => URL.revokeObjectURL(url), 1000);
    },
    format(value) {
      return number(value);
    },
    go(changes) {
      this.$panel.view.open("search-insights", {
        query: query({ ...this.current, ...changes })
      });
    },
    load(key) {
      try {
        return localStorage.getItem("search-insights$" + key);
      } catch {
        return null;
      }
    },
    onCell({ row }) {
      if (this.tab === "clicks") {
        return this.openPage(row.key);
      }

      this.openQuery(row.key);
    },
    onFilter(value) {
      this.filterInput = value ?? "";
      clearTimeout(this.filterTimeout);
      this.filterTimeout = setTimeout(() => this.go({ q: this.filterInput, page: null }), 300);
    },
    onHeader({ column }) {
      if (!column.sort) {
        return;
      }

      const ascending = ["term", "title", "first", "last", "position"].includes(column.sort);
      const dir =
        this.sortKey === column.sort
          ? this.sortDir === "asc"
            ? "desc"
            : "asc"
          : ascending
            ? "asc"
            : "desc";

      this.go({ sort: column.sort, dir, page: null });
    },
    onIgnore() {
      this.$panel.drawer.close();
    },
    openPage(key) {
      if (key) {
        this.$panel.drawer.open("search-insights/page/" + key, { query: this.drawerQuery });
      }
    },
    openQuery(key) {
      if (key) {
        this.$panel.drawer.open("search-insights/query/" + key, { query: this.drawerQuery });
      }
    },
    points(current, previous) {
      if (current === null || current === undefined || previous === null || previous === undefined) {
        return null;
      }

      const value = (current - previous) * 100;

      return (value >= 0 ? "↑ " : "↓ ") + this.$t("owebstudio.search-insights.points", { change: number(Math.abs(value), 1) });
    },
    queryValue(row, showNew = false) {
      return {
        term: row.term,
        lang: row.lang,
        showLang: this.languages.length > 1 && !this.lang,
        isNew: showNew && row.isNew
      };
    },
    rowOptions(row) {
      if (this.tab === "clicks") {
        const page = row.source;

        return [
          { icon: "chart", text: this.$t("owebstudio.search-insights.details"), click: () => this.openPage(row.key) },
          { icon: "edit-line", text: this.$t("owebstudio.search-insights.drawer.openPanel"), link: page.link, disabled: !page.link },
          { icon: "open", text: this.$t("owebstudio.search-insights.drawer.openPage"), link: page.url, target: "_blank", disabled: !page.url }
        ];
      }

      const term = row.source.term;

      return [
        { icon: "chart", text: this.$t("owebstudio.search-insights.details"), click: () => this.openQuery(row.key) },
        {
          icon: "copy",
          text: this.$t("owebstudio.search-insights.copy"),
          disabled: term === "",
          click: async () => {
            await this.$helper.clipboard.write(term);
            this.$panel.notification.success(this.$t("owebstudio.search-insights.copied"));
          }
        },
        "-",
        {
          icon: "hidden",
          text: this.$t("owebstudio.search-insights.ignore"),
          dialog: "search-insights/ignore/" + encode(term),
          disabled: !this.canManage || term === ""
        }
      ];
    },
    setChartMode(mode) {
      this.chartMode = mode;

      try {
        localStorage.setItem("search-insights$chart", mode);
      } catch {
        // per viewer convenience only
      }
    },
    showAll(tab) {
      return {
        icon: "angle-right",
        text: this.$t("owebstudio.search-insights.list.showAll"),
        link: this.url({ tab })
      };
    },
    url(changes) {
      const params = new URLSearchParams(query({ ...this.current, q: null, sort: null, dir: null, page: null, ...changes }));
      const string = params.toString();

      return "/search-insights" + (string ? "?" + string : "");
    },
    versus(text) {
      return text ? text + " " + this.$t("owebstudio.search-insights.previous") : null;
    }
  }
};
</script>
