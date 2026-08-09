<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { init, use, type ECharts } from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { DimensionPoint, MetricResponse, Point, WidgetDefinition } from './types';
import { widgetPalette, widgetPalettes, type WidgetPaletteKey } from './palettes';

use([BarChart, LineChart, PieChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

const props = defineProps<{ widget: WidgetDefinition; gridX: number; gridY: number; data?: MetricResponse; loading: boolean; editing: boolean; interacting: boolean; interactionMode: 'drag' | 'resize' | null; selectedGroup: number | null; ticketSearchUrl: string; assetSearchUrl: string; licenceSearchUrl: string; changeSearchUrl: string; problemSearchUrl: string }>();
const emit = defineEmits<{ remove: [id: string]; rename: [id: string, title: string]; palette: [id: string, palette: WidgetPaletteKey]; selectGroup: [id: number | null]; interactionStart: [id: string, mode: 'drag' | 'resize', event: PointerEvent] }>();
const chartElement = ref<HTMLElement | null>(null);
const widgetElement = ref<HTMLElement | null>(null);
let chart: ECharts | null = null;
let resizeObserver: ResizeObserver | null = null;
const activePalette = computed(() => widgetPalette(props.widget.palette));
const donutColors = computed(() => activePalette.value.colors);

const points = computed(() => (props.data?.series ?? []) as Point[]);
const groupPoints = computed(() => (props.data?.series ?? []) as DimensionPoint[]);
const latestGroups = computed(() => {
  const latestDate = groupPoints.value.at(-1)?.date;
  return groupPoints.value.filter((point) => point.date === latestDate && (!props.selectedGroup || point.dimension_id === props.selectedGroup)).sort((a, b) => b.value - a.value);
});
const donutGroups = computed(() => {
  const groups = latestGroups.value;
  if (groups.length <= 5) return groups;
  return [...groups.slice(0, 4), { date: groups[0]?.date ?? '', dimension_id: -1, dimension: 'Other', value: groups.slice(4).reduce((sum, group) => sum + group.value, 0) }];
});
const recordRows = computed(() => (props.data?.rows ?? []).slice(0, props.widget.type === 'detail_table' ? 8 : 10));
const insightTop = computed(() => latestGroups.value[0]);
const matrixColumns = computed(() => [...new Map((props.data?.matrix ?? []).map(cell => [cell.column_id, cell.column])).entries()].slice(0, 6));
const matrixRows = computed(() => [...new Map((props.data?.matrix ?? []).map(cell => [cell.row_id, cell.row])).entries()]);
function matrixValue(rowId: number, columnId: number): number { return props.data?.matrix?.find(cell => cell.row_id === rowId && cell.column_id === columnId)?.value ?? 0; }
const kpiValue = computed(() => {
  const value = props.data?.value ?? points.value.at(-1)?.value;
  if (value === undefined) return 'Not available';
  if (['average_open_ticket_age', 'average_unassigned_time'].includes(props.widget.metric)) return `${(value / 86400).toFixed(1)} days`;
  if (['software_license_compliance_rate', 'sla_breach_rate'].includes(props.widget.metric)) return `${value.toFixed(1)}%`;
  if (props.widget.metric === 'assignment_changes_per_ticket') return value.toFixed(2);
  return value.toLocaleString();
});
const isGroupMetric = computed(() => props.widget.metric === 'historical_group_backlog');
const dimensionHeader = computed(() => {
  if (props.widget.metric === 'asset_inventory_by_state') return 'Lifecycle state';
  if (props.widget.metric === 'open_change_status_distribution') return 'Change status';
  if (props.widget.metric === 'open_problem_status_distribution') return 'Problem status';
  if (props.widget.metric === 'open_tickets_by_priority') return 'Priority';
  if (props.widget.metric === 'tickets_by_request_source') return 'Request source';
  if (['sla_breaches_by_technician', 'technician_workload_distribution'].includes(props.widget.metric)) return 'Technician';
  if (props.widget.metric === 'resolution_time_age_bands') return 'Resolution band';
  if (['prohibited_software_installations', 'unlicensed_software_installations'].includes(props.widget.metric)) return 'Software';
  if (props.widget.metric === 'incidents_by_operating_system') return 'Operating system';
  if (props.widget.metric === 'repeat_incident_computers') return 'Computer';
  if (props.widget.metric === 'created_vs_resolved_tickets') return 'Flow';
  return 'Service group';
});
const valueHeader = computed(() => {
  if (props.widget.metric === 'asset_inventory_by_state') return 'Computers';
  if (props.widget.metric === 'open_change_status_distribution') return 'Changes';
  if (props.widget.metric === 'open_problem_status_distribution') return 'Problems';
  if (['prohibited_software_installations', 'unlicensed_software_installations'].includes(props.widget.metric)) return 'Installations';
  if (['incidents_by_operating_system', 'repeat_incident_computers'].includes(props.widget.metric)) return 'Incidents';
  if (props.widget.metric === 'sla_breaches_by_technician') return 'Breaches';
  if (props.widget.metric === 'technician_workload_distribution') return 'Open';
  if (props.widget.metric === 'created_vs_resolved_tickets') return 'Tickets';
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
  if (props.widget.metric.startsWith('asset_') || ['stale_computer_inventory', 'low_disk_capacity_computers', 'computers_in_stock_over_30_days', 'incidents_by_operating_system', 'repeat_incident_computers'].includes(props.widget.metric)) return props.assetSearchUrl;
  if (props.widget.metric.startsWith('software_license_') || ['prohibited_software_installations', 'unlicensed_software_installations'].includes(props.widget.metric)) return props.licenceSearchUrl;
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
  const text = activePalette.value.text;
  const border = activePalette.value.border;
  const accent = activePalette.value.colors[0];
  if (props.widget.type === 'line') {
    if (props.widget.metric === 'created_vs_resolved_tickets') {
      const dates = [...new Set(groupPoints.value.map(point => point.date))];
      const dimensions = [...new Map(groupPoints.value.map(point => [point.dimension_id, point.dimension])).entries()];
      chart.setOption({
        animationDuration: 350,
        color: donutColors.value,
        textStyle: { color: text },
        legend: { type: 'plain', top: 0, textStyle: { color: text } },
        grid: { left: 46, right: 20, top: 48, bottom: 32 },
        tooltip: { trigger: 'axis' },
        xAxis: { type: 'category', data: dates, axisLabel: { color: text }, axisLine: { lineStyle: { color: border } } },
        yAxis: { type: 'value', minInterval: 1, axisLabel: { color: text }, splitLine: { lineStyle: { color: border } } },
        series: dimensions.map(([id, name]) => ({ type: 'line', name, smooth: true, symbol: 'none', lineStyle: { width: 3 }, data: dates.map(date => groupPoints.value.find(point => point.date === date && point.dimension_id === id)?.value ?? 0) })),
      }, true);
    } else {
      chart.setOption({ animationDuration: 350, textStyle: { color: text, fontSize: 10 }, grid: { left: 40, right: 12, top: 12, bottom: 28 }, tooltip: { trigger: 'axis' }, xAxis: { type: 'category', data: points.value.map(p => p.date), axisLabel: { color: text, fontSize: 10, hideOverlap: true }, axisLine: { lineStyle: { color: border } } }, yAxis: { type: 'value', minInterval: 1, axisLabel: { color: text, fontSize: 10 }, splitLine: { lineStyle: { color: border } } }, series: [{ type: 'line', smooth: true, symbol: 'none', lineStyle: { width: 2, color: accent }, itemStyle: { color: accent }, areaStyle: { color: accent, opacity: .1 }, data: points.value.map(p => p.value) }] }, true);
    }
  } else {
    const groups = props.widget.type === 'donut' ? donutGroups.value : latestGroups.value.slice(0, 8);
    if (props.widget.type === 'donut') {
      chart.setOption({
        color: donutColors.value,
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
      chart.setOption({ grid: { left: '34%', right: 18, top: 8, bottom: 24 }, tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } }, xAxis: { type: 'value', minInterval: 1, axisLabel: { fontSize: 10 }, splitLine: { lineStyle: { color: border } } }, yAxis: { type: 'category', inverse: true, data: groups.map(g => g.dimension), axisLabel: { color: text, fontSize: 10, lineHeight: 12, formatter: (value: string) => value.replace(/(.{18})\s+/g, '$1\n') } }, series: [{ type: 'bar', data: groups.map(g => ({ value: g.value, groupId: g.dimension_id })), barMaxWidth: 18, itemStyle: { color: accent, borderRadius: [0, 3, 3, 0] } }] }, true);
      chart.off('click'); chart.on('click', (event: any) => selectDimension(Number(event.data?.groupId) || 0));
    }
  }
}
let resizeTimer: number | undefined;
function resize(): void { if (resizeTimer) window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(() => chart?.resize({ animation: { duration: 0 } }), 150); }
watch(() => [props.data, props.widget.type, props.widget.palette, props.selectedGroup, props.loading], async () => { await nextTick(); draw(); }, { deep: true });
onMounted(() => { draw(); resizeObserver = new ResizeObserver(() => resize()); if (widgetElement.value) resizeObserver.observe(widgetElement.value); });
onBeforeUnmount(() => { if (resizeTimer) window.clearTimeout(resizeTimer); resizeObserver?.disconnect(); chart?.dispose(); });
</script>

<template>
  <article ref="widgetElement" class="card marifex-widget" :class="[`marifex-widget--${widget.type}`, `marifex-widget--palette-${activePalette.key}`, { 'marifex-widget--editing': editing, 'marifex-widget--dragging': interacting && interactionMode === 'drag', 'marifex-widget--resizing': interacting && interactionMode === 'resize' }]" :style="{ '--marifex-widget-rows': String(widget.h), gridColumn: `${gridX} / span ${widget.w}`, gridRow: `${gridY} / span ${widget.h}` }" :data-widget-id="widget.id" :data-widget-width="widget.w">
    <div class="card-header marifex-widget__header" :class="{ 'marifex-widget__drag-handle': editing }" @pointerdown="editing && emit('interactionStart', widget.id, 'drag', $event)">
      <div><span v-if="data?.source === 'live'" class="marifex-widget__kicker">Live GLPI</span><h2 class="card-title">{{ widget.title }}</h2></div>
      <div v-if="editing" class="marifex-widget__actions">
        <span class="marifex-drag-indicator" aria-hidden="true" title="Drag widget"><i></i><i></i><i></i><i></i><i></i><i></i></span>
        <button class="btn btn-sm btn-ghost-danger" type="button" title="Remove widget" @pointerdown.stop @click.stop="emit('remove', widget.id)">Remove</button>
      </div>
    </div>
    <div v-if="editing" class="marifex-widget__settings">
      <div><label class="form-label" :for="`title-${widget.id}`">Widget title</label><input :id="`title-${widget.id}`" class="form-control form-control-sm" :value="widget.title" maxlength="100" @change="emit('rename', widget.id, ($event.target as HTMLInputElement).value)"></div>
      <div><label class="form-label" :for="`palette-${widget.id}`">Color palette</label><select :id="`palette-${widget.id}`" class="form-select form-select-sm" :value="activePalette.key" @change="emit('palette', widget.id, ($event.target as HTMLSelectElement).value as WidgetPaletteKey)"><option v-for="palette in widgetPalettes" :key="palette.key" :value="palette.key">{{ palette.label }} · {{ palette.type }}</option></select></div>
    </div>
    <div class="card-body marifex-widget__body" :aria-busy="loading">
      <div v-if="loading" class="marifex-skeleton"><span></span><span></span><span></span></div>
      <template v-else-if="widget.type === 'kpi'">
        <strong class="marifex-executive-kpi">{{ kpiValue }}</strong>
        <div class="marifex-kpi-context"><span v-if="trend !== null" :class="trend > 0 ? 'marifex-semantic--risk' : 'marifex-semantic--healthy'">{{ trend > 0 ? '↑' : '↓' }} {{ Math.abs(trend).toFixed(1) }}% vs previous period</span><span v-else>No historical comparison available</span></div>
      </template>
      <template v-else-if="widget.type === 'insight'">
        <div v-if="insightTop" class="marifex-insight"><span>Leading finding</span><strong>{{ insightTop.dimension }}</strong><p>{{ insightTop.value.toLocaleString() }} current records</p><a :href="drilldown(insightTop.dimension_id)">Open detail</a></div>
        <p v-else class="text-secondary">No finding is available for this period.</p>
      </template>
      <template v-else-if="widget.type === 'attention'">
        <div class="marifex-attention-list"><a v-for="row in recordRows" :key="String(row.finding)" class="marifex-attention-row" :class="`marifex-attention-row--${row.severity}`" :href="row.target === 'assets' ? assetSearchUrl : row.target === 'licences' ? licenceSearchUrl : ticketSearchUrl"><span class="marifex-attention-dot"></span><span>{{ row.finding }}</span><strong>{{ Number(row.count).toLocaleString() }}</strong></a></div>
      </template>
      <template v-else-if="widget.type === 'detail_table'">
        <div class="table-responsive marifex-detail-table"><table class="table table-vcenter"><thead><tr><th>Record</th><th>Priority / state</th><th>Owner</th><th>Timing</th></tr></thead><tbody><tr v-for="row in recordRows" :key="Number(row.id)"><td><a :href="String(row.link)">#{{ row.id }} {{ row.title }}</a></td><td>{{ row.state ?? `Priority ${row.priority}` }}</td><td>{{ row.group }}</td><td>{{ row.timing ?? row.latest_solution_date }}</td></tr></tbody></table></div>
      </template>
      <template v-else-if="widget.type === 'matrix'">
        <div class="table-responsive marifex-matrix"><table class="table"><thead><tr><th>Priority</th><th v-for="column in matrixColumns" :key="column[0]">{{ column[1] }}</th></tr></thead><tbody><tr v-for="row in matrixRows" :key="row[0]"><th>{{ row[1] }}</th><td v-for="column in matrixColumns" :key="column[0]"><span :style="{ '--mx-heat': String(Math.min(1, matrixValue(row[0], column[0]) / 20)) }">{{ matrixValue(row[0], column[0]) }}</span></td></tr></tbody></table></div>
      </template>
      <template v-else-if="widget.type === 'table'">
        <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>{{ dimensionHeader }}</th><th class="text-end">{{ valueHeader }}</th><th></th></tr></thead><tbody><tr v-for="group in latestGroups.slice(0, 8)" :key="group.dimension_id" :class="{ 'table-active': isGroupMetric && selectedGroup === group.dimension_id }"><td><button v-if="isGroupMetric" class="marifex-link-button" type="button" @click="emit('selectGroup', group.dimension_id)">{{ group.dimension }}</button><a v-else :href="drilldown(group.dimension_id)">{{ group.dimension }}</a></td><td class="text-end fw-bold">{{ group.value.toLocaleString() }}</td><td class="text-end"><a :href="drilldown(group.dimension_id)" aria-label="Open matching GLPI records">Open</a></td></tr></tbody></table></div>
      </template>
      <div v-else-if="widget.type === 'donut'" class="marifex-donut-layout">
        <div ref="chartElement" class="marifex-widget__chart marifex-donut-layout__chart" role="img" :aria-label="widget.title"></div>
        <div class="marifex-donut-legend" :aria-label="`${dimensionHeader} legend`">
          <button v-for="(group, index) in donutGroups" :key="group.dimension_id" class="marifex-donut-legend__item" :class="{ 'is-selected': isGroupMetric && selectedGroup === group.dimension_id }" type="button" :title="`${group.dimension}: ${group.value.toLocaleString()}`" @click="group.dimension_id >= 0 && selectDimension(group.dimension_id)">
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
