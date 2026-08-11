<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { init, use, type ECharts } from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { DimensionPoint, InsightItem, MetricResponse, Point, WidgetDefinition } from './types';
import { widgetPalette, widgetPalettes, type WidgetPaletteKey } from './palettes';
import { paletteSupports, resolvedColors, type ChartPalette } from './chartPalettes';

use([BarChart, LineChart, PieChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

const props = defineProps<{ widget: WidgetDefinition; chartPalettes: ChartPalette[]; sectionLabel?: string; data?: MetricResponse; movement?: InsightItem; indicator?: string; loading: boolean; editing: boolean; interacting: boolean; interactionMode: 'drag' | 'resize' | null; selectedGroup: number | null; ticketSearchUrl: string; assetSearchUrl: string; licenceSearchUrl: string; changeSearchUrl: string; problemSearchUrl: string }>();
const emit = defineEmits<{ remove: [id: string]; rename: [id: string, title: string]; palette: [id: string, palette: WidgetPaletteKey]; chartPalette: [id: string, palette: string]; selectGroup: [id: number | null]; interactionStart: [id: string, mode: 'drag' | 'resize', event: PointerEvent] }>();
const chartElement = ref<HTMLElement | null>(null);
const widgetElement = ref<HTMLElement | null>(null);
const settingsOpen = ref(false);
const draftTitle = ref('');
const draftSurfacePalette = ref<WidgetPaletteKey>('cream_gold');
const draftChartPalette = ref('chart_cream_gold');
let chart: ECharts | null = null;
let resizeObserver: ResizeObserver | null = null;
const activePalette = computed(() => widgetPalette(props.widget.palette));
const activeChartPalette = computed(() => props.chartPalettes.find(item => item.id === props.widget.chartPalette) ?? props.chartPalettes[0]);
const chartColors = computed(() => activeChartPalette.value ? resolvedColors(activeChartPalette.value) : activePalette.value.colors);
const assignableChartPalettes = computed(() => props.chartPalettes.filter(item => paletteSupports(item, props.widget.requiredColorSlots)));
const donutColors = chartColors;
const announcedPoint = ref('');
let keyboardPoint = -1;

const points = computed(() => (props.data?.series ?? []) as Point[]);
const latestPoint = computed(() => points.value.at(-1));
const populationRateMetrics = ['software_license_compliance_rate', 'sla_breach_rate', 'customer_dissatisfaction_rate', 'refused_solution_rate', 'repeat_incident_asset_rate', 'licence_utilization_rate', 'licence_coverage_gap_rate'];
const isUnmeasurable = computed(() => populationRateMetrics.includes(props.widget.metric) && latestPoint.value?.sample_count === 0);
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
function toggleSettings(): void {
  if (settingsOpen.value) { settingsOpen.value = false; return; }
  draftTitle.value = props.widget.title;
  draftSurfacePalette.value = props.widget.palette;
  draftChartPalette.value = props.widget.chartPalette;
  settingsOpen.value = true;
  window.dispatchEvent(new CustomEvent('marifex-widget-settings', { detail: props.widget.id }));
}
function cancelSettings(): void { settingsOpen.value = false; }
function applySettings(): void {
  const title = draftTitle.value.trim();
  if (title) emit('rename', props.widget.id, title);
  emit('palette', props.widget.id, draftSurfacePalette.value);
  if (props.widget.requiredColorSlots > 0) emit('chartPalette', props.widget.id, draftChartPalette.value);
  settingsOpen.value = false;
}
function closeOtherSettings(event: Event): void { if ((event as CustomEvent<string>).detail !== props.widget.id) settingsOpen.value = false; }
const kpiValue = computed(() => {
  if (isUnmeasurable.value) return 'N/A';
  const value = props.data?.value ?? points.value.at(-1)?.value;
  if (value === undefined) return 'Not available';
  if (['average_open_ticket_age', 'average_unassigned_time'].includes(props.widget.metric)) return `${(value / 86400).toFixed(1)} days`;
  if (['first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds'].includes(props.widget.metric)) return value < 3600 ? `${(value / 60).toFixed(1)} min` : `${(value / 3600).toFixed(1)} hr`;
  if (populationRateMetrics.includes(props.widget.metric)) return `${value.toFixed(1)}%`;
  if (props.widget.metric === 'assignment_changes_per_ticket') return value.toFixed(2);
  return value.toLocaleString();
});
const isGroupMetric = computed(() => props.widget.metric === 'historical_group_backlog');
const evidenceMetrics = new Set(['historical_group_backlog','open_tickets_by_priority','tickets_by_request_source','created_tickets_by_request_source','sla_breaches_by_technician','technician_workload_distribution','asset_inventory_by_state','open_change_status_distribution','open_problem_status_distribution','resolution_time_age_bands','incidents_by_operating_system','open_incidents_by_assignment_group']);
const evidenceActionAllowed = computed(() => evidenceMetrics.has(props.widget.metric));
const dimensionHeader = computed(() => {
  if (props.widget.metric === 'asset_inventory_by_state') return 'Lifecycle state';
  if (props.widget.metric === 'open_change_status_distribution') return 'Change status';
  if (props.widget.metric === 'open_problem_status_distribution') return 'Problem status';
  if (props.widget.metric === 'open_tickets_by_priority') return 'Priority';
  if (['tickets_by_request_source', 'created_tickets_by_request_source'].includes(props.widget.metric)) return 'Request source';
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
const movementText = computed(() => {
  if (!props.movement) return null;
  const change = props.movement.absolute_change;
  const native = props.movement.unit === 'percent' ? `${Math.abs(change).toFixed(1)} pp` : Math.abs(change).toLocaleString();
  const previous = props.movement.unit === 'percent' ? `${props.movement.previous.toFixed(1)}%` : props.movement.previous.toLocaleString();
  return `${change >= 0 ? '↑' : '↓'} ${native} from ${previous}, ${props.movement.comparison_text}`;
});
const drilldown = (groupId?: number) => {
  if (props.widget.metric.startsWith('asset_') || ['stale_computer_inventory', 'low_disk_capacity_computers', 'computers_in_stock_over_30_days', 'incidents_by_operating_system', 'repeat_incident_computers', 'incident_linked_computers', 'repeat_incident_computers_90d', 'repeat_incident_asset_rate'].includes(props.widget.metric)) return props.assetSearchUrl;
  if (props.widget.metric.startsWith('software_license_') || props.widget.metric.startsWith('licence_') || ['prohibited_software_installations', 'unlicensed_software_installations'].includes(props.widget.metric)) return props.licenceSearchUrl;
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

const tooltipBase = { renderMode: 'richText' as const, confine: true, appendToBody: false, transitionDuration: 0, valueFormatter: (value: unknown) => Number(value).toLocaleString() };
function navigateChart(event: KeyboardEvent): void {
  if (!chart || !['line','bar','donut'].includes(props.widget.type)) return;
  const categories = props.widget.type === 'donut' ? donutGroups.value : latestGroups.value.slice(0, 8);
  const total = props.widget.type === 'line' ? Math.max(1, points.value.length || new Set(groupPoints.value.map(point => point.date)).size) : categories.length;
  if (!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(event.key)) return;
  event.preventDefault();
  if (event.key === 'Home') keyboardPoint = 0; else if (event.key === 'End') keyboardPoint = total - 1; else keyboardPoint = Math.max(0, Math.min(total - 1, keyboardPoint + (['ArrowRight','ArrowDown'].includes(event.key) ? 1 : -1)));
  chart.dispatchAction({ type: 'showTip', seriesIndex: 0, dataIndex: keyboardPoint });
  const point = props.widget.type === 'line' ? points.value[keyboardPoint] : categories[keyboardPoint];
  announcedPoint.value = point && 'dimension' in point ? `${props.widget.title}, ${point.dimension}, ${point.value.toLocaleString()}, ${props.data?.as_of ?? ''}` : point ? `${props.widget.title}, ${point.date}, ${point.value.toLocaleString()}, ${props.data?.as_of ?? ''}` : props.widget.title;
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
  const accent = chartColors.value[0] ?? activePalette.value.colors[0];
  const chartFontFamily = getComputedStyle(chartElement.value).fontFamily || 'Inter, system-ui, sans-serif';
  const axisLabel = { color: text, fontFamily: chartFontFamily, fontSize: 11, fontWeight: 600 };
  const legendText = { color: text, fontFamily: chartFontFamily, fontSize: 11, fontWeight: 600 };
  if (props.widget.type === 'line') {
    if (props.widget.metric === 'created_vs_resolved_tickets') {
      const dates = [...new Set(groupPoints.value.map(point => point.date))];
      const dimensions = [...new Map(groupPoints.value.map(point => [point.dimension_id, point.dimension])).entries()];
      chart.setOption({
        animationDuration: 350,
        color: donutColors.value,
        textStyle: legendText,
        legend: { type: 'plain', top: 0, textStyle: legendText, itemGap: 14 },
        grid: { left: 46, right: 20, top: 48, bottom: 32 },
        tooltip: { ...tooltipBase, trigger: 'axis' },
        xAxis: { type: 'category', data: dates, axisLabel: { ...axisLabel, hideOverlap: true }, axisLine: { lineStyle: { color: border } } },
        yAxis: { type: 'value', minInterval: 1, axisLabel, splitLine: { lineStyle: { color: border } } },
        series: dimensions.map(([id, name]) => ({ type: 'line', name, smooth: true, symbol: 'none', lineStyle: { width: 3 }, data: dates.map(date => groupPoints.value.find(point => point.date === date && point.dimension_id === id)?.value ?? 0) })),
      }, true);
    } else {
      chart.setOption({ animationDuration: 350, textStyle: legendText, grid: { left: 44, right: 12, top: 12, bottom: 30 }, tooltip: { trigger: 'axis' }, xAxis: { type: 'category', data: points.value.map(p => p.date), axisLabel: { ...axisLabel, hideOverlap: true }, axisLine: { lineStyle: { color: border } } }, yAxis: { type: 'value', minInterval: 1, axisLabel, splitLine: { lineStyle: { color: border } } }, series: [{ type: 'line', smooth: true, symbol: 'none', lineStyle: { width: 2, color: accent }, itemStyle: { color: accent }, areaStyle: { color: accent, opacity: .1 }, data: points.value.map(p => p.value) }] }, true);
    }
  } else {
    const groups = props.widget.type === 'donut' ? donutGroups.value : latestGroups.value.slice(0, 8);
    if (props.widget.type === 'donut') {
      chart.setOption({
        color: donutColors.value,
        tooltip: { ...tooltipBase, trigger: 'item' },
        series: [{
          type: 'pie',
          radius: ['46%', '72%'],
          center: ['50%', '50%'],
          label: { show: false },
          data: groups.map(g => ({ name: g.dimension, value: g.value, groupId: g.dimension_id })),
        }],
      }, true);
      chart.off('click'); if (evidenceActionAllowed.value) chart.on('click', (event: any) => selectDimension(Number(event.data?.groupId) || 0));
    } else {
      chart.setOption({ textStyle: legendText, grid: { left: '38%', right: 18, top: 8, bottom: 28 }, tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } }, xAxis: { type: 'value', minInterval: 1, axisLabel, splitLine: { lineStyle: { color: border } } }, yAxis: { type: 'category', inverse: true, data: groups.map(g => g.dimension), axisLabel: { ...axisLabel, lineHeight: 14, formatter: (value: string) => value.replace(/(.{18})\s+/g, '$1\n') } }, series: [{ type: 'bar', data: groups.map(g => ({ value: g.value, groupId: g.dimension_id })), barMaxWidth: 18, itemStyle: { color: accent, borderRadius: [0, 3, 3, 0] } }] }, true);
      chart.off('click'); if (evidenceActionAllowed.value) chart.on('click', (event: any) => selectDimension(Number(event.data?.groupId) || 0));
    }
  }
  chart.setOption({ tooltip: tooltipBase });
  const seriesCount = props.widget.metric === 'created_vs_resolved_tickets' ? new Set(groupPoints.value.map(point => point.dimension_id)).size : 1;
  chart.setOption({ series: Array.from({ length: Math.max(1, seriesCount) }, () => ({ emphasis: { focus: 'series' }, blur: { itemStyle: { opacity: .25 }, lineStyle: { opacity: .25 }, areaStyle: { opacity: .08 } } })) });
  if (props.widget.type === 'line' && props.widget.metric !== 'created_vs_resolved_tickets') chart.setOption({ series: [{ areaStyle: { color: accent, opacity: activeChartPalette.value?.areaOpacity ?? .25 } }] });
}
let resizeTimer: number | undefined;
function resize(): void { if (resizeTimer) window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(() => chart?.resize({ animation: { duration: 0 } }), 150); }
function dismissTooltip(event: PointerEvent): void { if (chart && widgetElement.value && !widgetElement.value.contains(event.target as Node)) chart.dispatchAction({ type: 'hideTip' }); }
watch(() => [props.data, props.widget.type, props.widget.palette, props.widget.chartPalette, props.selectedGroup, props.loading], async () => { await nextTick(); draw(); }, { deep: true });
watch(() => props.editing, editing => { if (!editing) settingsOpen.value = false; });
onMounted(() => { draw(); resizeObserver = new ResizeObserver(() => resize()); if (widgetElement.value) resizeObserver.observe(widgetElement.value); window.addEventListener('marifex-widget-settings', closeOtherSettings); document.addEventListener('pointerdown', dismissTooltip); });
onBeforeUnmount(() => { if (resizeTimer) window.clearTimeout(resizeTimer); resizeObserver?.disconnect(); chart?.dispose(); window.removeEventListener('marifex-widget-settings', closeOtherSettings); document.removeEventListener('pointerdown', dismissTooltip); });
</script>

<template>
  <article ref="widgetElement" class="card marifex-widget" :class="[`marifex-widget--${widget.type}`, `marifex-widget--palette-${activePalette.key}`, { 'marifex-widget--section-start': sectionLabel, 'marifex-widget--editing': editing, 'marifex-widget--settings-open': settingsOpen, 'marifex-widget--dragging': interacting && interactionMode === 'drag', 'marifex-widget--resizing': interacting && interactionMode === 'resize' }]" :style="{ '--marifex-widget-rows': String(widget.h), gridColumn: widget.x === undefined ? `span ${widget.w}` : `${widget.x + 1} / span ${widget.w}`, gridRow: widget.y === undefined ? `span ${widget.h}` : `${widget.y + 1} / span ${widget.h}` }" :data-widget-id="widget.id" :data-widget-width="widget.w">
    <div v-if="sectionLabel" class="marifex-widget__section-label">{{ sectionLabel }}</div>
    <div class="card-header marifex-widget__header" :class="{ 'marifex-widget__drag-handle': editing }" @pointerdown="editing && emit('interactionStart', widget.id, 'drag', $event)">
      <div><span v-if="data?.source === 'live'" class="marifex-widget__kicker">Live GLPI</span><h2 class="card-title">{{ widget.title }}</h2></div>
      <div v-if="editing" class="marifex-widget__actions">
        <span class="marifex-drag-indicator" aria-hidden="true" title="Drag widget"><i></i><i></i><i></i><i></i><i></i><i></i></span>
        <button class="btn btn-sm btn-ghost-secondary marifex-widget-action" type="button" title="Widget settings" aria-label="Widget settings" :aria-expanded="settingsOpen" @pointerdown.stop @click.stop="toggleSettings"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm8-3.5 2-1-2-3.5-2.2.5a8 8 0 0 0-1.7-1L15.5 5h-4L11 7a8 8 0 0 0-1.7 1L7 7.5 5 11l2 1a8 8 0 0 0 0 2L5 15l2 3.5 2.3-.5a8 8 0 0 0 1.7 1l.5 2h4l.6-2a8 8 0 0 0 1.7-1l2.2.5 2-3.5-2-1a8 8 0 0 0 0-2Z"/></svg></button>
        <button class="btn btn-sm btn-ghost-danger marifex-widget-action" type="button" title="Remove widget" aria-label="Remove widget" @pointerdown.stop @click.stop="emit('remove', widget.id)"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3m3 0-1 14H7L6 7m4 4v6m4-6v6"/></svg></button>
      </div>
    </div>
    <div v-if="editing && settingsOpen" class="marifex-widget__settings" role="dialog" aria-modal="true" :aria-labelledby="`settings-title-${widget.id}`" @pointerdown.stop>
      <div class="marifex-widget__settings-heading"><h3 :id="`settings-title-${widget.id}`">Widget settings</h3><p>Changes apply only after confirmation.</p></div>
      <div><label class="form-label" :for="`title-${widget.id}`">Widget title</label><input :id="`title-${widget.id}`" v-model="draftTitle" class="form-control form-control-sm" maxlength="100"></div>
      <div><label class="form-label" :for="`palette-${widget.id}`">Widget surface theme</label><select :id="`palette-${widget.id}`" v-model="draftSurfacePalette" class="form-select form-select-sm"><option v-for="palette in widgetPalettes" :key="palette.key" :value="palette.key">{{ palette.label }} · {{ palette.type }}</option></select><div class="form-hint">Controls the card background, border and text treatment.</div></div>
      <div v-if="widget.requiredColorSlots > 0"><label class="form-label" :for="`chart-palette-${widget.id}`">Chart series palette</label><select :id="`chart-palette-${widget.id}`" v-model="draftChartPalette" class="form-select form-select-sm"><option v-for="palette in assignableChartPalettes" :key="palette.id" :value="palette.id">{{ palette.name }} · r{{ palette.revision }}{{ palette.inherited ? ' · inherited' : '' }}</option></select><div class="form-hint">Controls plotted lines, bars or donut slices. Requires {{ widget.requiredColorSlots }} rendered colour slot{{ widget.requiredColorSlots === 1 ? '' : 's' }}.</div></div>
      <div v-else class="alert alert-info py-2 mb-0">This widget has no plotted chart series. Use the surface theme to change its appearance.</div>
      <div class="marifex-widget__settings-actions"><button class="btn btn-outline-secondary" type="button" @click="cancelSettings">Cancel</button><button class="btn btn-primary" type="button" :disabled="!draftTitle.trim()" @click="applySettings">Apply &amp; close</button></div>
    </div>
    <div class="card-body marifex-widget__body" :aria-busy="loading">
      <div v-if="loading" class="marifex-skeleton"><span></span><span></span><span></span></div>
      <template v-else-if="widget.type === 'kpi'">
        <strong class="marifex-executive-kpi">{{ kpiValue }}</strong>
        <div class="marifex-kpi-context"><span v-if="isUnmeasurable">No measurable population</span><span v-else-if="movementText" :class="`marifex-semantic--${movement?.direction === 'worsening' ? 'risk' : movement?.direction === 'improving' ? 'healthy' : 'neutral'}`">{{ movementText }}</span><span v-else>No material governed movement</span></div>
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
        <div ref="chartElement" class="marifex-widget__chart marifex-donut-layout__chart" role="img" tabindex="0" :aria-label="`${widget.title}. Use arrow keys to inspect data points.`" @keydown="navigateChart"></div>
        <div class="marifex-donut-legend" :aria-label="`${dimensionHeader} legend`">
          <button v-for="(group, index) in donutGroups" :key="group.dimension_id" class="marifex-donut-legend__item" :class="{ 'is-selected': isGroupMetric && selectedGroup === group.dimension_id }" type="button" :disabled="!evidenceActionAllowed || group.dimension_id < 0" :title="`${group.dimension}: ${group.value.toLocaleString()}`" @click="selectDimension(group.dimension_id)">
            <span class="marifex-donut-legend__swatch" :style="{ backgroundColor: donutColors[index % donutColors.length] }"></span>
            <span>{{ group.dimension }}</span>
          </button>
        </div>
      </div>
      <div v-else ref="chartElement" class="marifex-widget__chart" role="img" tabindex="0" :aria-label="`${widget.title}. Use arrow keys to inspect data points.`" @keydown="navigateChart"></div>
      <span class="visually-hidden" aria-live="polite">{{ announcedPoint }}</span>
      <span v-if="indicator" class="marifex-widget__indicator">{{ indicator }}</span>
    </div>
    <button v-if="editing" class="marifex-widget__resize-handle" type="button" aria-label="Resize widget" title="Drag to resize" @pointerdown.stop="emit('interactionStart', widget.id, 'resize', $event)"><svg aria-hidden="true" viewBox="0 0 16 16"><path d="M6 14h8V6M10 14h4v-4"/></svg></button>
  </article>
</template>
