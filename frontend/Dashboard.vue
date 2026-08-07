<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { init, use, type ECharts } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

use([LineChart, GridComponent, TooltipComponent, CanvasRenderer]);

type Point = { date: string; value: number };
type MetricResponse = {
  metric: string;
  label: string;
  source: 'live' | 'data_mart';
  value?: number;
  series?: Point[];
};

const props = defineProps<{ endpoint: string }>();
const loading = ref(true);
const error = ref('');
const live = ref<MetricResponse | null>(null);
const history = ref<MetricResponse | null>(null);
const averageAge = ref<MetricResponse | null>(null);
const chartElement = ref<HTMLElement | null>(null);
let chart: ECharts | null = null;

const openTickets = computed(() => live.value?.value?.toLocaleString() ?? 'Not available');
const averageAgeLabel = computed(() => {
  const seconds = averageAge.value?.series?.at(-1)?.value;
  if (seconds === undefined) return 'Not available';
  const days = seconds / 86400;
  return days < 1 ? `${Math.round(seconds / 3600)} hours` : `${days.toFixed(1)} days`;
});

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const [liveResponse, historyResponse, ageResponse] = await Promise.all([
      fetch(`${props.endpoint}/current_open_tickets`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
      fetch(`${props.endpoint}/historical_open_backlog`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
      fetch(`${props.endpoint}/average_open_ticket_age`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
    ]);
    if (!liveResponse.ok || !historyResponse.ok || !ageResponse.ok) throw new Error('Metric request failed');
    live.value = await liveResponse.json();
    history.value = await historyResponse.json();
    averageAge.value = await ageResponse.json();
    await nextTick();
    drawChart();
  } catch {
    error.value = 'Analytics data could not be loaded. Retry or check the GLPI automatic actions.';
  } finally {
    loading.value = false;
  }
}

function drawChart(): void {
  if (!chartElement.value || !history.value?.series) return;
  chart ??= init(chartElement.value);
  const styles = getComputedStyle(document.documentElement);
  const textColor = styles.getPropertyValue('--tblr-body-color').trim() || '#182433';
  const borderColor = styles.getPropertyValue('--tblr-border-color').trim() || '#dce1e7';
  chart.setOption({
    animationDuration: 300,
    textStyle: { color: textColor },
    grid: { left: 42, right: 18, top: 24, bottom: 30 },
    tooltip: { trigger: 'axis' },
    xAxis: { type: 'category', data: history.value.series.map((point) => point.date), axisLine: { lineStyle: { color: borderColor } } },
    yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: borderColor } } },
    series: [{ type: 'line', smooth: true, symbol: 'none', lineStyle: { width: 3 }, areaStyle: { opacity: 0.12 }, data: history.value.series.map((point) => point.value) }],
  });
}

function resize(): void { chart?.resize(); }

onMounted(() => { void load(); window.addEventListener('resize', resize); });
onBeforeUnmount(() => { window.removeEventListener('resize', resize); chart?.dispose(); });
</script>

<template>
  <section aria-labelledby="marifex-title">
    <header class="marifex-dashboard__header">
      <div>
        <p class="marifex-dashboard__eyebrow">MarifeX</p>
        <h1 id="marifex-title">Advanced Analytics</h1>
      </div>
      <button class="btn btn-outline-secondary" type="button" :disabled="loading" @click="load">Refresh</button>
    </header>

    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div class="marifex-dashboard__grid" :aria-busy="loading">
      <article class="card marifex-kpi">
        <div class="card-body">
          <span class="marifex-kpi__label">Current open tickets</span>
          <strong class="marifex-kpi__value">{{ loading ? '…' : openTickets }}</strong>
          <span class="badge bg-blue-lt">Live GLPI</span>
        </div>
      </article>
      <article class="card marifex-kpi">
        <div class="card-body">
          <span class="marifex-kpi__label">Average open ticket age</span>
          <strong class="marifex-kpi__value marifex-kpi__value--compact">{{ loading ? '...' : averageAgeLabel }}</strong>
          <span class="badge bg-purple-lt">Data Mart</span>
        </div>
      </article>
      <article class="card marifex-chart-card">
        <div class="card-header"><h2 class="card-title">Historical open backlog</h2><span class="badge bg-purple-lt">Data Mart</span></div>
        <div ref="chartElement" class="marifex-chart" role="img" aria-label="Historical open ticket backlog line chart"></div>
      </article>
    </div>
  </section>
</template>
