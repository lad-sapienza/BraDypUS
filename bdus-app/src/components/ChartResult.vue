<template>
  <div v-if="result" class="chart-result">
    <div v-if="result.type === 'metric'" class="metric-display">
      <span class="metric-value">{{ formattedMetricValue }}</span>
      <span class="metric-label">{{ result.label }}</span>
    </div>
    <component
      v-else-if="chartComponent"
      :is="chartComponent"
      :data="chartData"
      :options="chartOptions"
      class="chart-canvas"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import {
  Bar as BarChart,
  Line as LineChart,
  Pie as PieChart,
  Doughnut as DoughnutChart,
} from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  ArcElement,
  Title,
  Tooltip,
  Legend
)

// ── Props ──────────────────────────────────────────────────────────────
const props = defineProps({
  result: { type: Object, default: null },
  style:  { type: Object, default: () => ({}) },
})

const DEFAULT_PALETTE = ['#4f81bd', '#c0504d', '#9bbb59', '#8064a2', '#4bacc6', '#f79646']

/** Applies a fixed decimal count to a numeric value, if configured. Passes through otherwise. */
function formatValue(val, decimals) {
  const n = Number(val)
  if (decimals == null || !Number.isFinite(n)) return val
  return n.toFixed(decimals)
}

const chartData = computed(() => {
  const isMultiColor = ['pie', 'doughnut'].includes(props.result?.type)
  return {
    labels: props.result?.labels ?? [],
    datasets: [{
      label: 'value',
      data: props.result?.data ?? [],
      backgroundColor: isMultiColor
        ? DEFAULT_PALETTE.slice(0, props.result?.labels?.length ?? 1)
        : (props.style.color || DEFAULT_PALETTE[0]),
    }],
  }
})

const chartOptions = computed(() => {
  const decimals = props.style.decimals != null && props.style.decimals !== '' ? Number(props.style.decimals) : null
  const hasAxes   = ['bar', 'line'].includes(props.result?.type)

  const opts = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: (props.style.horizontal && props.result?.type === 'bar') ? 'y' : 'x',
    plugins: {
      legend: {
        display: props.style.legendPosition !== 'hidden',
        position: ['top', 'bottom', 'left', 'right'].includes(props.style.legendPosition) ? props.style.legendPosition : 'top',
      },
      tooltip: {
        callbacks: {
          label: (ctx) => `${ctx.dataset.label}: ${formatValue(ctx.parsed?.y ?? ctx.parsed?.x ?? ctx.parsed, decimals)}`,
        },
      },
    },
  }

  if (hasAxes) {
    // With indexAxis:'y' (horizontal bars), Chart.js swaps which scale id
    // holds values vs categories: 'x' becomes the value axis, 'y' the
    // category axis. Min/max/decimals must follow the value axis, not
    // always 'y', or they'd be misapplied to the category labels.
    const valueAxisKey = opts.indexAxis === 'y' ? 'x' : 'y'
    opts.scales = {
      [valueAxisKey]: {
        min: props.style.yMin != null && props.style.yMin !== '' ? Number(props.style.yMin) : undefined,
        max: props.style.yMax != null && props.style.yMax !== '' ? Number(props.style.yMax) : undefined,
        ticks: decimals != null ? { callback: (v) => formatValue(v, decimals) } : {},
      },
    }
  }

  return opts
})

/** Metric-type result value, formatted with the configured decimal count. */
const formattedMetricValue = computed(() => {
  const decimals = props.style.decimals != null && props.style.decimals !== '' ? Number(props.style.decimals) : null
  return formatValue(props.result?.value, decimals)
})

const CHART_COMPONENTS = { bar: BarChart, line: LineChart, pie: PieChart, doughnut: DoughnutChart }
const chartComponent = computed(() => CHART_COMPONENTS[props.result?.type] ?? null)
</script>

<style scoped>
.chart-result {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.chart-canvas {
  width: 100%;
  height: 100%;
}

.metric-display {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1rem;
  gap: 0.3rem;
  background: var(--p-content-hover-background);
  border-radius: 8px;
}

.metric-value {
  font-size: 2rem;
  font-weight: 700;
  color: var(--p-primary-color);
}

.metric-label {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}
</style>
