<template>
  <k-dialog
    :cancel-button="false"
    :submit-button="{ icon: 'check', text: $t('close') }"
    :visible="visible"
    class="k-search-insights-dialog"
    size="medium"
    @cancel="$panel.dialog.close()"
    @submit="$panel.dialog.close()"
  >
    <k-label type="section">{{ $t("owebstudio.search-insights.ignored") }}</k-label>

    <k-table
      :columns="columns"
      :empty="$t('owebstudio.search-insights.ignored.empty')"
      :index="false"
      :rows="rows"
    >
      <template v-if="canManage" #options="{ row }">
        <k-button
          :title="$t('owebstudio.search-insights.restore')"
          icon="undo"
          @click="restore(row.term)"
        />
      </template>
    </k-table>

    <p class="k-help">{{ $t("owebstudio.search-insights.ignored.help") }}</p>
    <p v-if="option?.length" class="k-help">
      {{ $t("owebstudio.search-insights.ignored.option", { terms: option.join(", ") }) }}
    </p>
  </k-dialog>
</template>

<script>
import { date } from "../helpers.js";

export default {
  inheritAttrs: false,
  props: {
    canManage: Boolean,
    option: Array,
    terms: Array,
    visible: Boolean
  },
  data() {
    return {
      list: this.terms ?? []
    };
  },
  computed: {
    columns() {
      return {
        term: { label: this.$t("owebstudio.search-insights.column.query"), mobile: true },
        created: { label: this.$t("owebstudio.search-insights.column.ignored"), width: "1/3", mobile: true }
      };
    },
    rows() {
      return this.list.map((item) => ({ term: item.term, created: date(item.created) }));
    }
  },
  methods: {
    async restore(term) {
      await this.$panel.api.post("search-insights/restore", { term });

      this.list = this.list.filter((item) => item.term !== term);
      this.$panel.notification.success(this.$t("owebstudio.search-insights.restored", { term }));
      await this.$panel.view.reload();
    }
  }
};
</script>
