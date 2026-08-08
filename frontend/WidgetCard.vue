<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { init, use, type ECharts } from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { DimensionPoint, MetricResponse, Point, WidgetDefinition } from './types';

use([BarChart, LineChart, PieChart, GridComponent, TooltipComponent, CanvasRenderer]);

const props = defineProps<{ widget: WidgetDefinition; gridX: number; gridY: number; data?: MetricResponse; loading: boolean; editing: boolean; interacting: boolean; interactionMode: 'drag' | 'resize' | null; selectedGroup: number | null; ticketSearchUrl: string; assetSearchUrl: string; licenceSearchUrl: string; changeSearchUrl: string; problemSearchUrl: string }>();
const emit = defineEmits<{ remove: [id: string]; rename: [id: string, title: string]; selectGroup: [id: number | null]; interactionStart: [id: string, mode: 'drag' | 'resize', event: PointerEvent] }>();
const chartElement = ref<HTMLElement | null>(null);
const widgetElement = ref<HTMLElement | null>(null);
let chart: ECharts | null = null;
let resizeObserver: ResizeObserver | null = null;
const donutColors = ['#5470c6', '#b5db27', '#525a7d', '#ff6685', '#8a63c7', '#50b52d', '#ffd000', '#00a5c8', '#ff8746', '#59607e', '#9a60b4', '#ea7ccc'];

const points = computed(() => (props.data?.series ?? []) as Point[]);
const groupPoints = computed(() => (props.data?.series ?? []) as DimensionPoint[]);
const latestGroups = computed(() => {
  const latestDate = groupPoints.value.at(-1)?.date;
  return groupPoints.value.filter((point) => point.date === latestDate && (!props.selectedGroup || point.dimension_id === props.selectedGroup)).sort((a, b) => b.value - a.value);
});
const kpiValue = computed(() => {
  const value = props.data?.value ?? points.value.at(-1)?.value;
  if (value === undefined) return 'Not available';
  if (props.widget.metric === 'average_open_ticket_age') return `${(value / 86400).toFixed(1)} days`;
  if (props.widget.metric === 'software_license_compliance_rate') return `${value.toFixed(1)}%`;
  return value.toLocaleString();
});
const isGroupMetric = computed(() => props.widget.metric === 'historical_group_backlog');
const dimensionHeader = computed(() => {
  if (props.widget.metric === 'asset_inventory_by_state') return 'Lifecycle state';
  if (props.widget.metric === 'open_change_status_distribution') return 'Change status';
  if (props.widget.metric === 'open_problem_status_distribution') return 'Problem status';
  return 'Service group';
});
const valueHeader = computed(() => {
  if (props.widget.metric === 'asset_inventory_by_state') return 'Computers';
  if (props.widget.metric === 'open_change_status_distribution') return 'Changes';
  if (props.widget.metric === 'open_problem_status_distribution') return 'Problems';
  return 'Open';
});
const trend = computed(() => {
  if (points.value.length < 2) return null;
  const current = points.value.at(-1)!.value;
  const previous = points.value.at(-2)!.value;
  if (previous === 0) return null;
  return ((current - previous) / previous) * 100;
});
const drilldown = (groupId?: number) => {
  if (props.widget.metric.startsWith('asset_') || props.widget.metric === 'stale_computer_inventory') return props.assetSearchUrl;
  if (props.widget.metric.startsWith('software_license_')) return props.licenceSearchUrl;
  if (props.widget.metric.includes('change')) return props.changeSearchUrl;
  if (props.widget.metric.includes('problem')) return props.problemSearchUrl;
  if (!groupId) return props.ticketSearchUrl;
  const query = new URLSearchParams({ group_id: String(groupId) });
  return `${props.ticketSearchUrl}?${query.toString()}`;
};
function selectDimension(id: number): void {
  if (isGroupMetric.value) emit('selectGroup', id);
  else window.location.href = drilldown(id);
}

function draw(): void {
  if (!chartElement.value || !['line', 'bar', 'donut'].includes(props.widget.type)) return;
  if (chart && chart.getDom() !== chartElement.value) {
    chart.dispose();
    chart = null;
  }
  chart ??= init(chartElement.value);
  const styles = getComputedStyle(document.documentElement);
  const text = styles.getPropertyValue('--tblr-body-color').trim() || '#182433';
  const border = styles.getPropertyValue('--tblr-border-color').trim() || '#dce1e7';
  const accent = styles.getPropertyValue('--tblr-primary').trim() || '#206bc4';
  if (props.widget.type === 'line') {
    chart.setOption({ animationDuration: 350, textStyle: { color: text }, grid: { left: 46, right: 20, top: 24, bottom: 32 }, tooltip: { trigger: 'axis' }, xAxis: { type: 'category', data: points.value.map(p => p.date), axisLine: { lineStyle: { color: border } } }, yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: border } } }, series: [{ type: 'line', smooth: true, symbol: 'none', lineStyle: { width: 3, color: accent }, areaStyle: { opacity: .12 }, data: points.value.map(p => p.value) }] }, true);
  } else {
    const groups = latestGroups.value.slice(0, 12);
    if (props.widget.type === 'donut') {
      chart.setOption({
        color: donutColors,
        tooltip: { trigger: 'item' },
        series: [{
          type: 'pie',
          radius: ['46%', '72%'],
          center: ['50%', '50%'],
          label: { show: false },
          data: groups.map(g => ({ name: g.dimension, value: g.value, groupId: g.dimension_id })),
        }],
      }, true);
      chart.off('click'); chart.on('click', (event: any) => selectDimension(Number(event.data?.groupId) || 0));
    } else {
      chart.setOption({ grid: { left: 150, right: 24, top: 12, bottom: 28 }, tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } }, xAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: border } } }, yAxis: { type: 'category', inverse: true, data: groups.map(g => g.dimension), axisLabel: { color: text, width: 135, overflow: 'truncate' } }, series: [{ type: 'bar', data: groups.map(g => ({ value: g.value, groupId: g.dimension_id })), barMaxWidth: 24, itemStyle: { color: accent, borderRadius: [0, 5, 5, 0] } }] }, true);
      chart.off('click'); chart.on('click', (event: any) => selectDimension(Number(event.data?.groupId) || 0));
    }
  }
}
function resize(): void { chart?.resize({ animation: { duration: 0 } }); }
watch(() => [props.data, props.widget.type, props.selectedGroup, props.loading], async () => { await nextTick(); draw(); }, { deep: true });
onMounted(() => { draw(); resizeObserver = new ResizeObserver(() => resize()); if (widgetElement.value) resizeObserver.observe(widgetElement.value); });
onBeforeUnmount(() => { resizeObserver?.disconnect(); chart?.dispose(); });
</script>

<template>
  <article ref="widgetElement" class="card marifex-widget" :class="[`marifex-widget--${widget.type}`, { 'marifex-widget--editing': editing, 'marifex-widget--dragging': interacting && interactionMode === 'drag', 'marifex-widget--resizing': interacting && interactionMode === 'resize' }]" :style="{ '--marifex-widget-rows': String(widget.h), gridColumn: `${gridX} / span ${widget.w}`, gridRow: `${gridY} / span ${widget.h}` }" :data-widget-id="widget.id">
    <div class="card-header marifex-widget__header" :class="{ 'marifex-widget__drag-handle': editing }" @pointerdown="editing && emit('interactionStart', widget.id, 'drag', $event)">
      <div><span v-if="data?.source === 'live'" class="marifex-widget__kicker">Live GLPI</span><h2 class="card-title">{{ widget.title }}</h2></div>
      <div v-if="editing" class="marifex-widget__actions">
        <span class="marifex-drag-indicator" aria-hidden="true" title="Drag widget"><i></i><i></i><i></i><i></i><i></i><i></i></span>
        <button class="btn btn-sm btn-ghost-danger" type="button" title="Remove widget" @pointerdown.stop @click.stop="emit('remove', widget.id)">Remove</button>
      </div>
    </div>
    <div v-if="editing" class="marifex-widget__rename"><input class="form-control form-control-sm" :value="widget.title" maxlength="100" aria-label="Widget title" @change="emit('rename', widget.id, ($event.target as HTMLInputElement).value)"></div>
    <div class="card-body marifex-widget__body" :aria-busy="loading">
      <div v-if="loading" class="marifex-skeleton"><span></span><span></span><span></span></div>
      <template v-else-if="widget.type === 'kpi'">
        <strong class="marifex-executive-kpi">{{ kpiValue }}</strong>
        <div class="marifex-kpi-context"><span v-if="trend !== null" :class="trend > 0 ? 'text-red' : 'text-green'">{{ trend > 0 ? '+' : '' }}{{ trend.toFixed(1) }}% day over day</span><span v-else>Current certified value</span></div>
        <a class="btn btn-sm btn-outline-primary mt-3" :href="drilldown(selectedGroup ?? undefined)">Open in GLPI</a>
      </template>
      <template v-else-if="widget.type === 'table'">
        <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>{{ dimensionHeader }}</th><th class="text-end">{{ valueHeader }}</th><th></th></tr></thead><tbody><tr v-for="group in latestGroups" :key="group.dimension_id" :class="{ 'table-active': isGroupMetric && selectedGroup === group.dimension_id }"><td><button v-if="isGroupMetric" class="marifex-link-button" type="button" @click="emit('selectGroup', group.dimension_id)">{{ group.dimension }}</button><a v-else :href="drilldown(group.dimension_id)">{{ group.dimension }}</a></td><td class="text-end fw-bold">{{ group.value.toLocaleString() }}</td><td class="text-end"><a :href="drilldown(group.dimension_id)" aria-label="Open matching GLPI records">Open</a></td></tr></tbody></table></div>
      </template>
      <div v-else-if="widget.type === 'donut'" class="marifex-donut-layout">
        <div ref="chartElement" class="marifex-widget__chart marifex-donut-layout__chart" role="img" :aria-label="widget.title"></div>
        <div class="marifex-donut-legend" :aria-label="`${dimensionHeader} legend`">
          <button v-for="(group, index) in latestGroups.slice(0, 12)" :key="group.dimension_id" class="marifex-donut-legend__item" :class="{ 'is-selected': isGroupMetric && selectedGroup === group.dimension_id }" type="button" :title="`${group.dimension}: ${group.value.toLocaleString()}`" @click="selectDimension(group.dimension_id)">
            <span class="marifex-donut-legend__swatch" :style="{ backgroundColor: donutColors[index % donutColors.length] }"></span>
            <span>{{ group.dimension }}</span>
          </button>
        </div>
      </div>
      <div v-else ref="chartElement" class="marifex-widget__chart" role="img" :aria-label="widget.title"></div>
    </div>
    <button v-if="editing" class="marifex-widget__resize-handle" type="button" aria-label="Resize widget" title="Drag to resize" @pointerdown.stop="emit('interactionStart', widget.id, 'resize', $event)"><svg aria-hidden="true" viewBox="0 0 16 16"><path d="M6 14h8V6M10 14h4v-4"/></svg></button>
  </article>
</template>
