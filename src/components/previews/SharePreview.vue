<template>
  <p class="k-search-insights-share-field-preview">
    <template v-if="value !== null && value !== undefined && value !== ''">
      <span :data-series="column.series ?? 'results'" class="k-search-insights-bar">
        <span :style="{ width: Math.min(100, value * 100) + '%' }" />
      </span>
      <span class="k-search-insights-share-value">{{ text }}</span>
    </template>
    <span v-else class="k-search-insights-dimmed">–</span>
  </p>
</template>

<script>
import { percent } from "../../helpers.js";

export default {
  props: {
    column: Object,
    row: Object,
    value: [Number, String]
  },
  computed: {
    text() {
      return percent(this.value, 0);
    }
  }
};
</script>
