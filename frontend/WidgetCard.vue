<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { init, use, type ECharts } from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { DimensionPoint, MetricResponse, Point, WidgetDefinition } from './types';

use([BarChart, LineChart, PieChart, GridComponent, TooltipComponent, CanvasRenderer]);

const props = defineProps<{ widget: WidgetDefinition; data?: MetricResponse; loading: boolean; editing: boolean; selectedGroup: number | null; ticketSearchUrl: string }>();
const emit = defineEmits<{ remove: [id: string]; move: [id: string, direction: -1 | 1]; resize: [id: string, dimension: 'w' | 'h']; rename: [id: string, title: string]; selectGroup: [id: number | null] }>();
const chartElement = ref<HTMLElement | null>(null);
let chart: ECharts | null = null;
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
  return value.toLocaleString();
});
const trend = computed(() => {
  if (points.value.length < 2) return null;
  const current = points.value.at(-1)!.value;
  const previous = points.value.at(-2)!.value;
  if (previous === 0) return null;
  return ((current - previous) / previous) * 100;
});
const drilldown = (groupId?: number) => {
  if (!groupId) return props.ticketSearchUrl;
  const query = new URLSearchParams({ group_id: String(groupId) });
  return `${props.ticketSearchUrl}?${query.toString()}`;
};

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
      chart.off('click'); chart.on('click', (event: any) => emit('selectGroup', Number(event.data?.groupId) || null));
    } else {
      chart.setOption({ grid: { left: 150, right: 24, top: 12, bottom: 28 }, tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } }, xAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: border } } }, yAxis: { type: 'category', inverse: true, data: groups.map(g => g.dimension), axisLabel: { color: text, width: 135, overflow: 'truncate' } }, series: [{ type: 'bar', data: groups.map(g => ({ value: g.value, groupId: g.dimension_id })), barMaxWidth: 24, itemStyle: { color: accent, borderRadius: [0, 5, 5, 0] } }] }, true);
      chart.off('click'); chart.on('click', (event: any) => emit('selectGroup', Number(event.data?.groupId) || null));
    }
  }
}
function resize(): void { chart?.resize(); draw(); }
watch(() => [props.data, props.widget.type, props.selectedGroup, props.loading], async () => { await nextTick(); draw(); }, { deep: true });
onMounted(() => { draw(); window.addEventListener('resize', resize); });
onBeforeUnmount(() => { window.removeEventListener('resize', resize); chart?.dispose(); });
</script>

<template>
  <article class="card marifex-widget" :class="[`marifex-widget--${widget.type}`, { 'marifex-widget--editing': editing }]" :style="{ gridColumn: `span ${widget.w}`, minHeight: `${widget.h * 5.25}rem` }" :draggable="editing" :data-widget-id="widget.id">
    <div class="card-header marifex-widget__header">
      <div><span class="marifex-widget__kicker">{{ data?.source === 'live' ? 'Live GLPI' : 'Analytics Data Mart' }}</span><h2 class="card-title">{{ widget.title }}</h2></div>
      <div v-if="editing" class="marifex-widget__actions">
        <button class="btn btn-sm btn-ghost-secondary" type="button" title="Move left" @click="emit('move', widget.id, -1)">&#8592;</button>
        <button class="btn btn-sm btn-ghost-secondary" type="button" title="Move right" @click="emit('move', widget.id, 1)">&#8594;</button>
        <button class="btn btn-sm btn-ghost-secondary" type="button" title="Cycle widget width" @click="emit('resize', widget.id, 'w')">W {{ widget.w }}</button>
        <button class="btn btn-sm btn-ghost-secondary" type="button" title="Cycle widget height" @click="emit('resize', widget.id, 'h')">H {{ widget.h }}</button>
        <button class="btn btn-sm btn-ghost-danger" type="button" title="Remove" @click="emit('remove', widget.id)">Remove</button>
      </div>
    </div>
    <div v-if="editing" class="marifex-widget__rename"><input class="form-control form-control-sm" :value="widget.title" maxlength="100" aria-label="Widget title" @change="emit('rename', widget.id, ($event.target as HTMLInputElement).value)"></div>
    <div class="card-body marifex-widget__body" :aria-busy="loading">
      <div v-if="loading" class="marifex-skeleton"><span></span><span></span><span></span></div>
      <template v-else-if="widget.type === 'kpi'">
        <strong class="marifex-executive-kpi">{{ kpiValue }}</strong>
        <div class="marifex-kpi-context"><span v-if="trend !== null" :class="trend > 0 ? 'text-red' : 'text-green'">{{ trend > 0 ? '+' : '' }}{{ trend.toFixed(1) }}% day over day</span><span v-else>Current certified value</span></div>
        <a class="btn btn-sm btn-outline-primary mt-3" :href="drilldown(selectedGroup ?? undefined)">View tickets</a>
      </template>
      <template v-else-if="widget.type === 'table'">
        <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Service group</th><th class="text-end">Open</th><th></th></tr></thead><tbody><tr v-for="group in latestGroups" :key="group.dimension_id" :class="{ 'table-active': selectedGroup === group.dimension_id }"><td><button class="marifex-link-button" type="button" @click="emit('selectGroup', group.dimension_id)">{{ group.dimension }}</button></td><td class="text-end fw-bold">{{ group.value.toLocaleString() }}</td><td class="text-end"><a :href="drilldown(group.dimension_id)" aria-label="Open filtered GLPI tickets">Open</a></td></tr></tbody></table></div>
      </template>
      <div v-else-if="widget.type === 'donut'" class="marifex-donut-layout">
        <div ref="chartElement" class="marifex-widget__chart marifex-donut-layout__chart" role="img" :aria-label="widget.title"></div>
        <div class="marifex-donut-legend" aria-label="Service group legend">
          <button v-for="(group, index) in latestGroups.slice(0, 12)" :key="group.dimension_id" class="marifex-donut-legend__item" :class="{ 'is-selected': selectedGroup === group.dimension_id }" type="button" :title="`${group.dimension}: ${group.value.toLocaleString()}`" @click="emit('selectGroup', group.dimension_id)">
            <span class="marifex-donut-legend__swatch" :style="{ backgroundColor: donutColors[index % donutColors.length] }"></span>
            <span>{{ group.dimension }}</span>
          </button>
        </div>
      </div>
      <div v-else ref="chartElement" class="marifex-widget__chart" role="img" :aria-label="widget.title"></div>
    </div>
  </article>
</template>
