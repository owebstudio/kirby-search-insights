<template>
  <p class="k-search-insights-change-field-preview">
    <k-tag v-if="value.isNew" :text="$t('owebstudio.search-insights.new')" element="span" theme="positive" />
    <span v-else>{{ text }}</span>
  </p>
</template>

<script>
import { change } from "../../helpers.js";

export default {
  props: {
    column: Object,
    row: Object,
    value: Object
  },
  computed: {
    text() {
      return change(this.value.current, this.value.previous) ?? "–";
    }
  }
};
</script>
