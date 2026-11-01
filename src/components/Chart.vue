<template>
  <div
    ref="chart"
    :style="{ height: height + 'px' }"
    class="k-search-insights-chart"
    @mouseleave="active = null"
  >
    <svg v-if="width" :width="width" :height="height" :aria-label="label" role="img">
      <g class="k-search-insights-chart-grid">
        <template v-for="tick in ticks" :key="tick">
          <line :x1="margin.left" :x2="width - margin.right" :y1="y(tick) + 0.5" :y2="y(tick) + 0.5" />
          <text :x="margin.left - 8" :y="y(tick) + 4" text-anchor="end">{{ format(tick) }}</text>
        </template>
      </g>
      <g>
        <template v-for="(bar, index) in bars" :key="index">
          <path v-if="bar.results" :d="bar.results" class="k-search-insights-chart-results" />
          <path v-if="bar.zero" :d="bar.zero" class="k-search-insights-chart-zero" />
        </template>
      </g>
      <g class="k-search-insights-chart-grid">
        <template v-for="(item, index) in data" :key="index">
          <text
            v-if="(data.length - 1 - index) % labelEvery === 0"
            :x="margin.left + index * slot + slot / 2"
            :y="height - 6"
            text-anchor="middle"
          >
            {{ short(item) }}
          </text>
        </template>
      </g>
      <g>
        <rect
          v-for="(item, index) in data"
          :key="index"
          :x="margin.left + index * slot"
          :y="margin.top"
          :width="slot"
          :height="plotHeight"
          :data-active="active === index"
          class="k-search-insights-chart-hit"
          @mouseenter="active = index"
        />
      </g>
    </svg>

    <div v-if="tooltip" :style="tooltip.style" class="k-search-insights-tooltip">
      <strong>{{ tooltip.title }}</strong>
      <template v-if="mode === 'stacked'">
        <div>
          <span><i data-series="results" />{{ $t("owebstudio.search-insights.chart.results") }}</span>
          <span>{{ format(tooltip.item.searches - tooltip.item.zero) }}</span>
        </div>
        <div>
          <span><i data-series="zero" />{{ $t("owebstudio.search-insights.chart.none") }}</span>
          <span>{{ format(tooltip.item.zero) }}</span>
        </div>
        <div>
          <span>{{ $t("owebstudio.search-insights.chart.total") }}</span>
          <span>{{ format(tooltip.item.searches) }}</span>
        </div>
      </template>
      <div v-else>
        <span>{{ $t("owebstudio.search-insights.searches") }}</span>
        <span>{{ format(tooltip.item.searches) }}</span>
      </div>
    </div>
  </div>
</template>

<script>
import { date, number } from "../helpers.js";

/**
 * Bar chart of searches per day or week. Stacked: with and without results.
 */
export default {
  props: {
    data: {
      type: Array,
      default: () => []
    },
    height: {
      type: Number,
      default: 220
    },
    label: String,
    /**
     * `stacked`, `single` or `zero` (one series in the no-results color)
     */
    mode: {
      type: String,
      default: "stacked"
    }
  },
  data() {
    return {
      active: null,
      margin: { top: 10, right: 2, bottom: 22, left: 40 },
      width: 0
    };
  },
  computed: {
    bars() {
      const base = this.margin.top + this.plotHeight;
      const gap = Math.max(2, Math.min(this.slot * 0.25, 8));
      const width = Math.max(1, this.slot - gap);

      return this.data.map((item, index) => {
        const x = this.margin.left + index * this.slot + gap / 2;
        const zero = this.mode === "stacked" ? item.zero : 0;
        const results = ((item.searches - zero) / this.scale.max) * this.plotHeight;
        const none = (zero / this.scale.max) * this.plotHeight;

        return {
          results: results > 0 && this.mode !== "zero" ? this.path(x, base - results, width, results, zero ? 0 : 4) : null,
          zero:
            this.mode === "zero"
              ? this.path(x, base - results, width, results, 4)
              : none > 0
                ? this.path(x, base - results - none - (results > 0 ? 2 : 0), width, none, 4)
                : null
        };
      });
    },
    labelEvery() {
      return Math.max(1, Math.ceil(this.data.length / Math.max(2, Math.floor(this.plotWidth / 64))));
    },
    plotHeight() {
      return this.height - this.margin.top - this.margin.bottom;
    },
    plotWidth() {
      return this.width - this.margin.left - this.margin.right;
    },
    scale() {
      const max = Math.max(1, ...this.data.map((item) => item.searches));
      const raw = max / 4;
      const magnitude = 10 ** Math.floor(Math.log10(raw));
      const normalized = raw / magnitude;
      const step = Math.max(
        1,
        (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 2.5 ? 2.5 : normalized <= 5 ? 5 : 10) * magnitude
      );

      return { step, max: step * Math.ceil(max / step) };
    },
    slot() {
      return this.plotWidth / Math.max(1, this.data.length);
    },
    ticks() {
      const ticks = [];

      for (let tick = 0; tick <= this.scale.max + 1e-9; tick += this.scale.step) {
        ticks.push(tick);
      }

      return ticks;
    },
    tooltip() {
      const item = this.data[this.active];

      if (!item) {
        return null;
      }

      const left = this.margin.left + (this.active + 0.5) * this.slot;

      return {
        item,
        title: this.long(item),
        style: {
          left: Math.min(Math.max(left, 80), this.width - 80) + "px",
          top: Math.max(this.y(item.searches), 48) + "px"
        }
      };
    }
  },
  mounted() {
    this.observer = new ResizeObserver(([entry]) => {
      this.width = Math.max(240, Math.floor(entry.contentRect.width));
    });
    this.observer.observe(this.$refs.chart);
  },
  beforeUnmount() {
    this.observer?.disconnect();
  },
  methods: {
    format(value) {
      return number(value);
    },
    long(item) {
      if (item.from === item.to) {
        return date(item.from, { weekday: "short", day: "numeric", month: "short", year: "numeric" });
      }

      return this.$t("owebstudio.search-insights.chart.weekOf", { date: date(item.from) });
    },
    path(x, y, width, height, radius) {
      const r = Math.max(0, Math.min(radius, width / 2, height));

      return `M${x},${y + height}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${y + height}Z`;
    },
    short(item) {
      return date(item.from, { day: "numeric", month: "short" });
    },
    y(value) {
      return this.margin.top + this.plotHeight - (value / this.scale.max) * this.plotHeight;
    }
  }
};
</script>

<style>
.k-search-insights-chart {
  position: relative;
}
.k-search-insights-chart svg {
  display: block;
  overflow: visible;
}
.k-search-insights-chart-grid line {
  stroke: var(--color-border-dimmed);
  stroke-width: 1;
}
.k-search-insights-chart-grid text {
  fill: var(--color-text-dimmed);
  font-size: 11px;
  font-variant-numeric: tabular-nums;
}
.k-search-insights-chart-results {
  fill: var(--search-insights-color-results);
}
.k-search-insights-chart-zero {
  fill: var(--search-insights-color-zero);
}
.k-search-insights-chart-hit {
  fill: transparent;
}
.k-search-insights-chart-hit[data-active="true"] {
  fill: light-dark(rgba(0, 0, 0, 0.04), rgba(255, 255, 255, 0.05));
}
.k-search-insights-tooltip {
  position: absolute;
  z-index: 5;
  pointer-events: none;
  transform: translate(-50%, calc(-100% - 8px));
  padding: var(--spacing-2) var(--spacing-3);
  border-radius: var(--rounded);
  background: var(--color-gray-950);
  color: var(--color-white);
  box-shadow: var(--shadow-xl);
  font-size: var(--text-xs);
  line-height: 1.5;
  white-space: nowrap;
}
.k-search-insights-tooltip strong {
  display: block;
  margin-bottom: 2px;
  font-weight: var(--font-semi);
}
.k-search-insights-tooltip div {
  display: flex;
  justify-content: space-between;
  gap: var(--spacing-4);
}
.k-search-insights-tooltip i {
  display: inline-block;
  width: 8px;
  height: 8px;
  margin-inline-end: 6px;
  border-radius: 2px;
}
</style>
