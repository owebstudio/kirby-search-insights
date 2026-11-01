<template>
  <p class="k-search-insights-similar-field-preview">
    <button
      v-if="value?.term"
      :title="$t('owebstudio.search-insights.column.similar')"
      type="button"
      @click.stop="open"
    >
      <k-icon type="angle-right" />
      <span class="k-search-insights-term">{{ value.term }}</span>
      <span class="k-search-insights-dimmed">· {{ searches }}</span>
    </button>
    <span v-else class="k-search-insights-dimmed">–</span>
  </p>
</template>

<script>
import { number } from "../../helpers.js";

export default {
  props: {
    column: Object,
    row: Object,
    value: Object
  },
  computed: {
    searches() {
      return number(this.value.searches);
    }
  },
  methods: {
    open() {
      this.column.open?.(this.value.key);
    }
  }
};
</script>
