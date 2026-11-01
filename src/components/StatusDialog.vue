<template>
  <k-dialog
    :cancel-button="false"
    :size="size"
    :submit-button="{ icon: 'check', text: $t('close') }"
    :visible="visible"
    class="k-search-insights-dialog"
    @cancel="$panel.dialog.close()"
    @submit="$panel.dialog.close()"
  >
    <k-label type="section">{{ $t("owebstudio.search-insights.status") }}</k-label>

    <dl class="k-search-insights-status">
      <template v-for="row in rows" :key="row.label">
        <dt>{{ row.label }}</dt>
        <dd :data-ok="row.ok">
          <k-icon :type="row.ok ? 'check' : 'cancel-small'" />
          <span>{{ row.text }}</span>
        </dd>
      </template>
    </dl>

    <p class="k-help">{{ $t("owebstudio.search-insights.status.privacy") }}</p>
  </k-dialog>
</template>

<script>
import { date } from "../helpers.js";

export default {
  inheritAttrs: false,
  props: {
    size: {
      type: String,
      default: "large"
    },
    status: Object,
    visible: Boolean
  },
  computed: {
    rows() {
      const status = this.status;
      const t = (key, data) => this.$t("owebstudio.search-insights.status." + key, data);
      const lastSearch = status.lastSearch ? date(status.lastSearch) : t("never");

      return [
        {
          label: t("tracking"),
          text: (status.auto ? t("auto", status.auto) : t("helper")) + " · " + t("last", { date: lastSearch }),
          ok: Boolean(status.lastSearch)
        },
        {
          label: t("clicks"),
          text: status.clicks ? t("clicks.on", { date: status.lastClick ? date(status.lastClick) : t("never") }) : t("clicks.off"),
          ok: status.clicks
        },
        {
          label: t("storage"),
          text: "SQLite · " + status.database + " · " + this.$helper.file.niceSize(status.size),
          ok: status.driver
        },
        {
          label: t("retention"),
          text: t("retention.days", { days: status.retention }),
          ok: true
        },
        {
          label: t("dnt"),
          text: t(status.respectDnt ? "dnt.on" : "dnt.off"),
          ok: status.respectDnt
        },
        {
          label: t("consent"),
          text: t(status.consent ? "consent.on" : "consent.off"),
          ok: status.consent
        },
        {
          label: t("panelUsers"),
          text: t(status.panelUsers ? "panelUsers.on" : "panelUsers.off"),
          ok: !status.panelUsers
        },
        {
          label: t("bots"),
          text: t("bots.text"),
          ok: true
        },
        {
          label: this.$t("owebstudio.search-insights.ignored"),
          text: String(status.ignored),
          ok: true
        }
      ];
    }
  }
};
</script>
