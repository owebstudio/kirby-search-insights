<template>
  <p :data-exists="value.exists" class="k-search-insights-page-field-preview">
    <k-icon :type="value.icon" />
    <span class="k-search-insights-page-title">{{ value.title }}</span>
    <span v-if="value.path !== value.title" class="k-search-insights-page-path">{{ value.path }}</span>
  </p>
</template>

<script>
export default {
  props: {
    column: Object,
    row: Object,
    value: Object
  }
};
</script>
