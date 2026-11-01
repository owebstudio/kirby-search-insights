import "./styles.css";
import Chart from "./components/Chart.vue";
import Empty from "./components/Empty.vue";
import IgnoredDialog from "./components/IgnoredDialog.vue";
import PageDrawer from "./components/PageDrawer.vue";
import Previews from "./components/Previews.js";
import QueryDrawer from "./components/QueryDrawer.vue";
import StatusDialog from "./components/StatusDialog.vue";
import View from "./components/View.vue";

panel.plugin("owebstudio/search-insights", {
  components: {
    ...Previews,
    "k-search-insights-chart": Chart,
    "k-search-insights-empty": Empty,
    "k-search-insights-ignored-dialog": IgnoredDialog,
    "k-search-insights-page-drawer": PageDrawer,
    "k-search-insights-query-drawer": QueryDrawer,
    "k-search-insights-status-dialog": StatusDialog,
    "k-search-insights-view": View
  }
});
