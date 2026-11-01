import ChangePreview from "./previews/ChangePreview.vue";
import PagePreview from "./previews/PagePreview.vue";
import QueryPreview from "./previews/QueryPreview.vue";
import SharePreview from "./previews/SharePreview.vue";
import SimilarPreview from "./previews/SimilarPreview.vue";
import TrendPreview from "./previews/TrendPreview.vue";

/**
 * Table cells, used with `type: "search-insights-…"` in `k-table` columns
 */
export default {
  "k-search-insights-change-field-preview": ChangePreview,
  "k-search-insights-page-field-preview": PagePreview,
  "k-search-insights-query-field-preview": QueryPreview,
  "k-search-insights-share-field-preview": SharePreview,
  "k-search-insights-similar-field-preview": SimilarPreview,
  "k-search-insights-trend-field-preview": TrendPreview
};
