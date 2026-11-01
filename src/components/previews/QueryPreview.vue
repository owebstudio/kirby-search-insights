<template>
  <p class="k-search-insights-query-field-preview">
    <span :data-other="value.term === ''" class="k-search-insights-term">{{ term }}</span>
    <k-tag v-if="value.lang && value.showLang" :text="value.lang.toUpperCase()" element="span" theme="light" />
    <k-tag v-if="value.isNew" :text="$t('owebstudio.search-insights.new')" element="span" theme="positive" />
  </p>
</template>

<script>
export default {
  props: {
    column: Object,
    row: Object,
    value: Object
  },
  computed: {
    term() {
      return this.value.term === "" ? this.$t("owebstudio.search-insights.other") : this.value.term;
    }
  }
};
</script>
