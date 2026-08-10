<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import WidgetCard from './WidgetCard.vue';
import InsightStrip from './InsightStrip.vue';
import type { DashboardTemplate, DashboardWorkspace, InsightItem, InsightResponse, MetricResponse, ReportSchedule, SavedDashboard, WidgetDefinition } from './types';
import { defaultWidgetPalette, type WidgetPaletteKey } from './palettes';

const props = defineProps<{ metricEndpoint: string; insightEndpoint: string; definitionEndpoint: string; csrfToken: string; ticketSearchUrl: string; assetSearchUrl: string; licenceSearchUrl: string; changeSearchUrl: string; problemSearchUrl: string; reportExportUrl: string; reportScheduleEndpoint: string; canExport: boolean; canSchedule: boolean }>();
const dashboard = ref<SavedDashboard | null>(null);
const dashboards = ref<DashboardWorkspace['dashboards']>([]);
const templates = ref<DashboardTemplate[]>([]);
const metrics = ref<Record<string, MetricResponse>>({});
const insightData = ref<InsightResponse | null>(null);
const insightLoading = ref(false);
const insightError = ref('');
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const editing = ref(false);
const catalogOpen = ref(false);
const templateOpen = ref(false);
const scheduleOpen = ref(false);
const schedules = ref<ReportSchedule[]>([]);
const scheduleError = ref('');
const scheduleForm = ref({ name: 'Executive report', format: 'pdf' as 'pdf' | 'csv', frequency: 'weekly' as 'daily' | 'weekly' | 'monthly', send_hour: 8, weekday: 1, monthday: 1, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC', recipients: '' });
const selectedTemplate = ref('executive');
const newDashboardName = ref('Executive Operations Command');
const selectedGroup = ref<number | null>(null);
const gridElement = ref<HTMLElement | null>(null);
const interactingWidget = ref<string | null>(null);
const interactionMode = ref<'drag' | 'resize' | null>(null);
let interaction: { id: string; mode: 'drag' | 'resize'; pointerId: number; startX: number; startY: number; startW: number; startH: number } | null = null;
let dragGhost: HTMLElement | null = null;
let editSnapshot: SavedDashboard | null = null;
let refreshTimer: number | undefined;

const catalog: Array<Omit<WidgetDefinition, 'id' | 'palette'>> = [
  { metric: 'current_open_tickets', type: 'kpi', title: 'Open now', w: 3, h: 2 },
  { metric: 'average_open_ticket_age', type: 'kpi', title: 'Average ticket age', w: 3, h: 2 },
  { metric: 'average_open_ticket_age', type: 'line', title: 'Ticket age trajectory', w: 6, h: 4 },
  { metric: 'historical_open_backlog', type: 'line', title: 'Enterprise backlog trajectory', w: 6, h: 4 },
  { metric: 'historical_open_backlog', type: 'bar', title: 'Backlog by day', w: 6, h: 4 },
  { metric: 'historical_group_backlog', type: 'donut', title: 'Workload concentration', w: 6, h: 4 },
  { metric: 'historical_group_backlog', type: 'bar', title: 'Group workload comparison', w: 6, h: 4 },
  { metric: 'historical_group_backlog', type: 'table', title: 'Service ownership ranking', w: 6, h: 4 },
  { metric: 'open_tickets_by_priority', type: 'donut', title: 'Open tickets by priority', w: 6, h: 4 },
  { metric: 'unassigned_open_tickets', type: 'kpi', title: 'Unassigned open tickets', w: 3, h: 2 },
  { metric: 'average_unassigned_time', type: 'kpi', title: 'Average unassigned age', w: 3, h: 2 },
  { metric: 'tickets_approaching_sla_breach', type: 'kpi', title: 'Approaching SLA breach', w: 3, h: 2 },
  { metric: 'sla_breach_count', type: 'kpi', title: 'Open SLA breaches', w: 3, h: 2 },
  { metric: 'sla_breach_rate', type: 'kpi', title: 'SLA breach rate', w: 3, h: 2 },
  { metric: 'sla_breaches_by_technician', type: 'bar', title: 'SLA breaches by technician', w: 6, h: 4 },
  { metric: 'tickets_by_request_source', type: 'donut', title: 'Tickets by request source', w: 6, h: 4 },
  { metric: 'created_vs_resolved_tickets', type: 'line', title: 'Created versus resolved tickets', w: 6, h: 4 },
  { metric: 'assignment_changes_per_ticket', type: 'kpi', title: 'Assignment changes per ticket', w: 3, h: 2 },
  { metric: 'technician_workload_distribution', type: 'bar', title: 'Technician workload distribution', w: 6, h: 4 },
  { metric: 'unsatisfied_survey_responses', type: 'kpi', title: 'Unsatisfied responses', w: 3, h: 2 },
  { metric: 'resolution_time_age_bands', type: 'bar', title: 'Resolution-time age bands', w: 6, h: 4 },
  { metric: 'asset_inventory_total', type: 'kpi', title: 'Managed computers', w: 3, h: 2 },
  { metric: 'asset_inventory_by_state', type: 'donut', title: 'Computer lifecycle distribution', w: 6, h: 4 },
  { metric: 'stale_computer_inventory', type: 'kpi', title: 'Inventory stale over 30 days', w: 3, h: 2 },
  { metric: 'prohibited_software_installations', type: 'table', title: 'Software marked invalid', w: 6, h: 4 },
  { metric: 'unlicensed_software_installations', type: 'table', title: 'Installations above entitlement', w: 6, h: 4 },
  { metric: 'low_disk_capacity_computers', type: 'kpi', title: 'Low disk capacity', w: 3, h: 2 },
  { metric: 'computers_in_stock_over_30_days', type: 'kpi', title: 'In stock over 30 days', w: 3, h: 2 },
  { metric: 'incidents_by_operating_system', type: 'bar', title: 'Incidents by operating system', w: 6, h: 4 },
  { metric: 'repeat_incident_computers', type: 'table', title: 'Computers with repeated incidents', w: 6, h: 4 },
  { metric: 'software_license_entitlements', type: 'kpi', title: 'Licence entitlements', w: 3, h: 2 },
  { metric: 'software_license_allocations', type: 'kpi', title: 'Allocated licence seats', w: 3, h: 2 },
  { metric: 'software_license_overallocated_seats', type: 'kpi', title: 'Overallocated seats', w: 3, h: 2 },
  { metric: 'software_license_compliance_rate', type: 'kpi', title: 'Licence compliance', w: 3, h: 2 },
  { metric: 'open_changes', type: 'kpi', title: 'Open changes', w: 3, h: 2 },
  { metric: 'daily_change_volume', type: 'line', title: 'Change demand trajectory', w: 6, h: 4 },
  { metric: 'daily_change_resolutions', type: 'line', title: 'Change resolution trajectory', w: 6, h: 4 },
  { metric: 'open_change_status_distribution', type: 'donut', title: 'Open changes by status', w: 6, h: 4 },
  { metric: 'open_problems', type: 'kpi', title: 'Open problems', w: 3, h: 2 },
  { metric: 'daily_problem_volume', type: 'line', title: 'Problem demand trajectory', w: 6, h: 4 },
  { metric: 'daily_problem_resolutions', type: 'line', title: 'Problem resolution trajectory', w: 6, h: 4 },
  { metric: 'open_problem_status_distribution', type: 'donut', title: 'Open problems by status', w: 6, h: 4 },
  { metric: 'latest_solution_refused_tickets', type: 'detail_table', title: 'Latest solutions refused', w: 8, h: 7 },
  { metric: 'open_incidents_by_assignment_group', type: 'bar', title: 'Open incidents by assignment group', w: 7, h: 6 },
  { metric: 'open_tickets_priority_category_matrix', type: 'matrix', title: 'Priority by ITIL category', w: 8, h: 7 },
  { metric: 'active_sla_exceptions', type: 'detail_table', title: 'Active SLA exceptions', w: 8, h: 7 },
  { metric: 'operational_attention', type: 'attention', title: 'Operational attention', w: 7, h: 6 },
  { metric: 'sla_breaches_by_technician', type: 'insight', title: 'Top SLA pressure', w: 4, h: 3 },
];
const definition = computed(() => dashboard.value?.definition);
const hasGroupFilter = computed(() => definition.value?.widgets.some(widget => supportsGroup(widget.metric)) ?? false);
const dashboardSchedules = computed(() => schedules.value.filter(schedule => schedule.dashboard_definitions_id === dashboard.value?.id));
const groups = computed(() => {
  const groupMetric = metrics.value[metricKey('historical_group_backlog', null)];
  const points = (groupMetric?.series ?? []) as Array<{ dimension_id?: number; dimension?: string }>;
  const unique = new Map<number, string>();
  points.forEach(point => { if (point.dimension_id && point.dimension) unique.set(point.dimension_id, point.dimension); });
  return [...unique.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
});
const selectedGroupName = computed(() => groups.value.find(group => group.id === selectedGroup.value)?.name);
const sectionLabels: Record<string, string> = {
  'executive-sla-list': 'Service health',
  'executive-group-incidents': 'Workload and ownership',
  'executive-unsatisfied': 'Customer experience',
  'executive-asset-stale': 'Asset attention',
  'executive-prohibited-software': 'Software and licence risk',
  'executive-change-open': 'Change and problem control',
};

function clone<T>(value: T): T { return JSON.parse(JSON.stringify(value)) as T; }
function range(): { from: string; to: string } {
  const to = new Date(); const from = new Date(); from.setDate(from.getDate() - (definition.value?.dateRangeDays ?? 30));
  return { from: toDate(from), to: toDate(to) };
}
function toDate(date: Date): string { return date.toISOString().slice(0, 10); }
function metricKey(metric: string, groupId: number | null = selectedGroup.value): string { return `${metric}:${groupId ?? 0}`; }
function supportsGroup(metric: string): boolean { return ['current_open_tickets', 'historical_open_backlog'].includes(metric); }
function dataFor(widget: WidgetDefinition): MetricResponse | undefined {
  const groupId = supportsGroup(widget.metric) ? selectedGroup.value : null;
  return metrics.value[metricKey(widget.metric, groupId)];
}
const widgetInsightKeys: Record<string, string> = {
  sla_breach_count: 'sla_breach_count_movement',
  sla_breach_rate: 'sla_breach_rate_movement',
  tickets_approaching_sla_breach: 'approaching_sla_movement',
  unsatisfied_survey_responses: 'unsatisfied_response_movement',
  software_license_overallocated_seats: 'licence_overallocation_movement',
  software_license_compliance_rate: 'licence_compliance_movement',
};
function insightFor(widget: WidgetDefinition): InsightItem | undefined {
  const key = widgetInsightKeys[widget.metric];
  return key ? insightData.value?.insights.find(item => item.key === key) : undefined;
}
function indicatorFor(widget: WidgetDefinition): string | undefined {
  const indicator = insightData.value?.indicators.find(item => item.metric === widget.metric);
  return indicator ? `${indicator.label} · ${indicator.value.toFixed(1)}%` : undefined;
}
function headers(): Record<string, string> {
  return { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Glpi-Csrf-Token': props.csrfToken, 'X-Requested-With': 'XMLHttpRequest' };
}
function adoptWorkspace(workspace: DashboardWorkspace): void {
  dashboard.value = workspace.dashboard;
  dashboards.value = workspace.dashboards;
  templates.value = workspace.templates;
  selectedGroup.value = workspace.dashboard.definition.filters.groupId;
  scheduleRefresh();
}
function scheduleRefresh(): void {
  if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
  refreshTimer = undefined;
  const minutes = definition.value?.refreshMinutes ?? 0;
  if (minutes > 0) refreshTimer = window.setInterval(() => void loadMetrics(), minutes * 60_000);
}

async function load(): Promise<void> {
  loading.value = true; error.value = '';
  try {
    const response = await fetch(props.definitionEndpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Dashboard definition request failed');
    adoptWorkspace(await response.json());
    await loadMetrics();
    if (props.canSchedule) void loadSchedules().catch(() => { scheduleError.value = 'Report schedules could not be loaded.'; });
  } catch { error.value = 'The analytics dashboard could not be loaded. Check the plugin automatic actions and try again.'; }
  finally { loading.value = false; }
}
function exportUrl(format: 'pdf' | 'csv'): string { return `${props.reportExportUrl}/${dashboard.value?.id ?? 0}/${format}`; }
async function loadSchedules(): Promise<void> {
  const response = await fetch(props.reportScheduleEndpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error('Report schedules could not be loaded');
  schedules.value = (await response.json()).schedules as ReportSchedule[];
}
function schedulePayload(schedule?: ReportSchedule): Record<string, unknown> {
  if (schedule) return { id: schedule.id, name: schedule.name, dashboard_id: schedule.dashboard_definitions_id, format: schedule.format, frequency: schedule.frequency, send_hour: schedule.send_hour, weekday: schedule.weekday, monthday: schedule.monthday, timezone: schedule.timezone, recipients: schedule.recipients, is_active: schedule.is_active };
  return { name: scheduleForm.value.name, dashboard_id: dashboard.value?.id, format: scheduleForm.value.format, frequency: scheduleForm.value.frequency, send_hour: scheduleForm.value.send_hour, weekday: scheduleForm.value.weekday, monthday: scheduleForm.value.monthday, timezone: scheduleForm.value.timezone, recipients: scheduleForm.value.recipients.split(/[;,]/).map(item => item.trim()).filter(Boolean), is_active: true };
}
async function writeSchedule(method: 'POST' | 'PUT' | 'DELETE', payload: Record<string, unknown>): Promise<void> {
  scheduleError.value = '';
  const response = await fetch(props.reportScheduleEndpoint, { method, credentials: 'same-origin', headers: headers(), body: JSON.stringify(payload) });
  if (!response.ok) { scheduleError.value = (await response.text()) || 'The report schedule could not be saved.'; return; }
  schedules.value = (await response.json()).schedules as ReportSchedule[];
}
async function createSchedule(): Promise<void> {
  if (!dashboard.value?.id) { scheduleError.value = 'Save this dashboard before scheduling it.'; return; }
  await writeSchedule('POST', schedulePayload());
  if (!scheduleError.value) scheduleForm.value.name = `${dashboard.value.name} report`;
}
async function toggleSchedule(schedule: ReportSchedule): Promise<void> { await writeSchedule('PUT', schedulePayload({ ...schedule, is_active: !schedule.is_active })); }
async function deleteSchedule(schedule: ReportSchedule): Promise<void> { await writeSchedule('DELETE', { id: schedule.id }); }
function openSchedules(): void { scheduleForm.value.name = `${dashboard.value?.name ?? 'Dashboard'} report`; scheduleOpen.value = true; void loadSchedules().catch(() => { scheduleError.value = 'Report schedules could not be loaded.'; }); }
async function loadMetrics(): Promise<void> {
  if (!definition.value) return;
  const { from, to } = range();
  const requests = new Map<string, { metric: string; groupId: number | null }>();
  definition.value.widgets.forEach(widget => {
    const groupId = supportsGroup(widget.metric) ? selectedGroup.value : null;
    requests.set(metricKey(widget.metric, groupId), { metric: widget.metric, groupId });
  });
  if (hasGroupFilter.value) requests.set(metricKey('historical_group_backlog', null), { metric: 'historical_group_backlog', groupId: null });
  const entries = await Promise.all([...requests.entries()].map(async ([key, request]) => {
    const params = new URLSearchParams({ from, to });
    if (request.groupId) params.set('group_id', String(request.groupId));
    const response = await fetch(`${props.metricEndpoint}/${request.metric}?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Metric request failed');
    return [key, await response.json()] as const;
  }));
  metrics.value = Object.fromEntries(entries);
  await loadInsights();
}
async function loadInsights(): Promise<void> {
  if (!definition.value) return;
  insightLoading.value = true; insightError.value = '';
  try {
    const params = new URLSearchParams({ horizon: String(definition.value.dateRangeDays) });
    if (selectedGroup.value) params.set('group_id', String(selectedGroup.value));
    const response = await fetch(`${props.insightEndpoint}?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Insight request failed');
    insightData.value = await response.json() as InsightResponse;
  } catch {
    insightData.value = null;
    insightError.value = 'Certified insights are temporarily unavailable.';
  } finally { insightLoading.value = false; }
}
async function applyFilters(): Promise<void> {
  if (definition.value) definition.value.filters.groupId = selectedGroup.value;
  loading.value = true; error.value = '';
  try { await loadMetrics(); } catch { error.value = 'The selected dashboard filters could not be applied.'; }
  finally { loading.value = false; }
}
async function persistFilters(): Promise<void> {
  if (!dashboard.value?.id || editing.value) { await applyFilters(); return; }
  if (definition.value) definition.value.filters.groupId = selectedGroup.value;
  await write('PUT', { id: dashboard.value.id, name: dashboard.value.name, definition: dashboard.value.definition });
}
async function persistRefresh(): Promise<void> {
  scheduleRefresh();
  if (!dashboard.value?.id || editing.value) return;
  await write('PUT', { id: dashboard.value.id, name: dashboard.value.name, definition: dashboard.value.definition });
}
async function write(method: 'POST' | 'PUT' | 'DELETE', payload: Record<string, unknown>): Promise<void> {
  saving.value = true; error.value = '';
  try {
    const response = await fetch(props.definitionEndpoint, { method, credentials: 'same-origin', headers: headers(), body: JSON.stringify(payload) });
    if (!response.ok) throw new Error('Dashboard operation failed');
    adoptWorkspace(await response.json());
    metrics.value = {};
    await loadMetrics();
  } catch { error.value = 'The dashboard change could not be saved. Refresh the page and try again.'; }
  finally { saving.value = false; }
}
async function save(): Promise<void> {
  if (!dashboard.value) return;
  await write('PUT', { id: dashboard.value.id, name: dashboard.value.name, definition: dashboard.value.definition });
  if (!error.value) { editing.value = false; editSnapshot = null; }
}
async function switchDashboard(id: number): Promise<void> {
  if (saving.value || dashboard.value?.id === id) return;
  editing.value = false; editSnapshot = null;
  await write('POST', { action: 'activate', id });
}
async function createDashboard(): Promise<void> {
  const name = newDashboardName.value.trim();
  if (!name) { error.value = 'Enter a dashboard name.'; return; }
  await write('POST', { action: 'create', template: selectedTemplate.value, name });
  if (!error.value) templateOpen.value = false;
}
async function duplicateDashboard(): Promise<void> {
  if (!dashboard.value?.id) return;
  await write('POST', { action: 'duplicate', id: dashboard.value.id, name: `${dashboard.value.name} copy` });
}
async function deleteDashboard(): Promise<void> {
  if (!dashboard.value?.id || !window.confirm(`Delete “${dashboard.value.name}”?`)) return;
  await write('DELETE', { id: dashboard.value.id }); editing.value = false; editSnapshot = null;
}
function openTemplatePicker(): void {
  selectedTemplate.value = templates.value[0]?.key ?? 'executive';
  newDashboardName.value = templates.value[0]?.name ?? 'New dashboard';
  templateOpen.value = true;
}
function chooseTemplate(template: DashboardTemplate): void { selectedTemplate.value = template.key; newDashboardName.value = template.name; }
function startEditing(): void { if (!dashboard.value) return; editSnapshot = clone(dashboard.value); editing.value = true; }
function cancelEditing(): void {
  if (editSnapshot) { dashboard.value = editSnapshot; selectedGroup.value = editSnapshot.definition.filters.groupId; }
  editSnapshot = null; editing.value = false; scheduleRefresh(); void loadMetrics();
}
function addWidget(item: Omit<WidgetDefinition, 'id' | 'palette'>): void {
  if (!definition.value || definition.value.widgets.length >= 40) return;
  definition.value.widgets.push({ ...item, palette: defaultWidgetPalette, id: `widget-${Date.now().toString(36)}` }); catalogOpen.value = false; if (!editing.value) startEditing(); void loadMetrics();
}
function removeWidget(id: string): void { if (definition.value && definition.value.widgets.length > 1) definition.value.widgets = definition.value.widgets.filter(widget => widget.id !== id); }
function renameWidget(id: string, title: string): void { const widget = definition.value?.widgets.find(item => item.id === id); const clean = title.trim(); if (widget && clean) widget.title = clean; }
function recolorWidget(id: string, palette: WidgetPaletteKey): void { const widget = definition.value?.widgets.find(item => item.id === id); if (widget) widget.palette = palette; }
function beginInteraction(id: string, mode: 'drag' | 'resize', event: PointerEvent): void {
  if (!editing.value || event.button !== 0) return;
  const widget = definition.value?.widgets.find(item => item.id === id); if (!widget) return;
  event.preventDefault();
  (event.currentTarget as HTMLElement | null)?.setPointerCapture?.(event.pointerId);
  interaction = { id, mode, pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, startW: widget.w, startH: widget.h };
  interactingWidget.value = id; interactionMode.value = mode;
  if (mode === 'drag') {
    const card = gridElement.value?.querySelector<HTMLElement>(`[data-widget-id="${CSS.escape(id)}"]`);
    const box = card?.getBoundingClientRect();
    if (box) {
      dragGhost = document.createElement('div');
      dragGhost.className = 'marifex-drag-ghost';
      dragGhost.textContent = widget.title;
      Object.assign(dragGhost.style, { left: `${box.left}px`, top: `${box.top}px`, width: `${box.width}px`, height: `${box.height}px` });
      document.body.appendChild(dragGhost);
    }
  }
  document.body.classList.add('marifex-layout-interacting');
}
function moveInteraction(event: PointerEvent): void {
  if (!interaction || event.pointerId !== interaction.pointerId || !definition.value || !gridElement.value) return;
  event.preventDefault();
  const widget = definition.value.widgets.find(item => item.id === interaction!.id); if (!widget) return;
  if (interaction.mode === 'resize') {
    const styles = getComputedStyle(gridElement.value);
    const gap = Number.parseFloat(styles.columnGap) || 16;
    const columnWidth = (gridElement.value.clientWidth - gap * 11) / 12;
    const rowHeight = Number.parseFloat(styles.gridAutoRows) || 84;
    const constraints = widget.type === 'kpi' ? { minW: 2, maxW: 4, heights: [2, 3] }
      : widget.type === 'insight' ? { minW: 3, maxW: 5, heights: [2, 3] }
      : ['line', 'bar', 'donut'].includes(widget.type) ? { minW: 4, maxW: 8, heights: [6, 7] }
      : widget.type === 'table' ? { minW: 5, maxW: 8, heights: [6, 7] }
      : ['detail_table', 'matrix'].includes(widget.type) ? { minW: 6, maxW: 12, heights: [7, 8] }
      : { minW: 6, maxW: 8, heights: [6, 7] };
    widget.w = Math.max(constraints.minW, Math.min(constraints.maxW, interaction.startW + Math.round((event.clientX - interaction.startX) / (columnWidth + gap))));
    const rawH = interaction.startH + Math.round((event.clientY - interaction.startY) / (rowHeight + gap));
    widget.h = constraints.heights.reduce((nearest, value) => Math.abs(value - rawH) < Math.abs(nearest - rawH) ? value : nearest);
    return;
  }
  if (dragGhost) dragGhost.style.transform = `translate3d(${event.clientX - interaction.startX}px, ${event.clientY - interaction.startY}px, 0)`;
  const styles = getComputedStyle(gridElement.value);
  const columnGap = Number.parseFloat(styles.columnGap) || 16;
  const rowGap = Number.parseFloat(styles.rowGap) || 16;
  const columnWidth = (gridElement.value.clientWidth - columnGap * 11) / 12;
  const rowHeight = Number.parseFloat(styles.gridAutoRows) || 32;
  const gridBox = gridElement.value.getBoundingClientRect();
  const x = Math.max(0, Math.min(12 - widget.w, Math.round((event.clientX - gridBox.left - (columnWidth * widget.w) / 2) / (columnWidth + columnGap))));
  const y = Math.max(0, Math.round((event.clientY - gridBox.top - (rowHeight * widget.h) / 2) / (rowHeight + rowGap)));
  const candidate = {
    left: gridBox.left + x * (columnWidth + columnGap),
    top: gridBox.top + y * (rowHeight + rowGap),
    right: gridBox.left + x * (columnWidth + columnGap) + widget.w * columnWidth + (widget.w - 1) * columnGap,
    bottom: gridBox.top + y * (rowHeight + rowGap) + widget.h * rowHeight + (widget.h - 1) * rowGap,
  };
  const blocked = Array.from(gridElement.value.querySelectorAll<HTMLElement>('.marifex-widget:not(.marifex-widget--dragging)')).some(card => {
    const box = card.getBoundingClientRect();
    return candidate.left < box.right - 2 && candidate.right > box.left + 2 && candidate.top < box.bottom - 2 && candidate.bottom > box.top + 2;
  });
  if (blocked) return;
  widget.x = x;
  widget.y = y;
}
function endInteraction(event?: PointerEvent): void {
  if (!interaction || (event && event.pointerId !== interaction.pointerId)) return;
  dragGhost?.remove(); dragGhost = null;
  interaction = null; interactingWidget.value = null; interactionMode.value = null;
  document.body.classList.remove('marifex-layout-interacting');
}
async function chooseGroup(id: number | null): Promise<void> { selectedGroup.value = selectedGroup.value === id ? null : id; await persistFilters(); }
onMounted(() => { void load(); window.addEventListener('pointermove', moveInteraction, { passive: false }); window.addEventListener('pointerup', endInteraction); window.addEventListener('pointercancel', endInteraction); });
onBeforeUnmount(() => { if (refreshTimer !== undefined) window.clearInterval(refreshTimer); window.removeEventListener('pointermove', moveInteraction); window.removeEventListener('pointerup', endInteraction); window.removeEventListener('pointercancel', endInteraction); dragGhost?.remove(); document.body.classList.remove('marifex-layout-interacting'); });
</script>

<template>
  <section class="marifex-command" aria-labelledby="marifex-title">
    <header class="marifex-command__hero">
      <div><p class="marifex-command__eyebrow">MarifeX Intelligence</p><h1 id="marifex-title">{{ dashboard?.name ?? 'Analytics Dashboard' }}</h1><p>Certified service intelligence inside your GLPI home workspace.</p></div>
      <div class="marifex-command__actions">
        <select v-if="dashboards.length" class="form-select marifex-dashboard-switcher" aria-label="Active analytics dashboard" :value="dashboard?.id ?? ''" :disabled="saving" @change="switchDashboard(Number(($event.target as HTMLSelectElement).value))"><option v-if="dashboard?.id === null" value="">Unsaved default</option><option v-for="item in dashboards" :key="item.id" :value="item.id">{{ item.name }}</option></select>
        <button class="btn btn-outline-secondary" type="button" @click="openTemplatePicker">New dashboard</button>
        <button v-if="dashboard?.id" class="btn btn-outline-secondary" type="button" :disabled="saving" @click="duplicateDashboard">Duplicate</button>
        <a v-if="props.canExport && dashboard?.id" class="btn btn-outline-secondary" :href="exportUrl('pdf')">Export PDF</a>
        <a v-if="props.canExport && dashboard?.id" class="btn btn-outline-secondary" :href="exportUrl('csv')">Export CSV</a>
        <button v-if="props.canSchedule && dashboard?.id" class="btn btn-outline-secondary" type="button" @click="openSchedules">Schedule</button>
        <button class="btn btn-outline-secondary" type="button" :disabled="loading" @click="loadMetrics">Refresh</button>
        <button v-if="!editing" class="btn btn-outline-primary" type="button" title="Edit dashboard layout" @click="startEditing"><svg aria-hidden="true" class="marifex-button-icon" viewBox="0 0 24 24"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Zm10-13 4 4"/></svg>Edit layout</button>
        <template v-else><button class="btn btn-success" type="button" :disabled="saving" @click="save">{{ saving ? 'Saving...' : 'Save dashboard' }}</button><button class="btn btn-outline-secondary" type="button" @click="cancelEditing">Cancel</button></template>
      </div>
    </header>

    <div v-if="editing && dashboard" class="card marifex-builderbar">
      <div><label class="form-label" for="marifex-dashboard-name">Dashboard name</label><input id="marifex-dashboard-name" v-model.trim="dashboard.name" class="form-control" maxlength="120"></div>
      <button class="btn btn-outline-primary" type="button" @click="catalogOpen = true">Add widget</button>
      <button v-if="dashboard.id" class="btn btn-outline-danger" type="button" @click="deleteDashboard">Delete dashboard</button>
      <small>The canvas keeps the saved view geometry while editing. Drag a card by its header, resize from its corner grip, and use the settings icon for title or palette without changing card size.</small>
    </div>

    <div v-if="definition" class="card marifex-filterbar">
      <div><label class="form-label" for="marifex-range">Dashboard horizon</label><select id="marifex-range" v-model.number="definition.dateRangeDays" class="form-select" @change="persistFilters"><option :value="7">7 days</option><option :value="30">30 days</option><option :value="90">90 days</option><option :value="180">180 days</option><option :value="365">365 days</option></select></div>
      <div v-if="hasGroupFilter"><label class="form-label" for="marifex-group">Assigned group</label><select id="marifex-group" v-model="selectedGroup" class="form-select" @change="persistFilters"><option :value="null">All service groups</option><option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option></select></div>
      <div><label class="form-label" for="marifex-refresh">Auto-refresh</label><select id="marifex-refresh" v-model.number="definition.refreshMinutes" class="form-select" @change="persistRefresh"><option :value="0">Manual</option><option :value="5">Every 5 minutes</option><option :value="15">Every 15 minutes</option><option :value="30">Every 30 minutes</option><option :value="60">Every hour</option></select></div>
      <div class="marifex-filterbar__status"><span class="marifex-pulse"></span><div><strong>Analytics current</strong><small>{{ selectedGroupName ? `Focused on ${selectedGroupName}` : 'Active GLPI entity scope' }}</small></div></div>
      <button v-if="hasGroupFilter && selectedGroup" class="btn btn-sm btn-ghost-secondary" type="button" @click="chooseGroup(null)">Clear group focus</button>
    </div>

    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <InsightStrip v-if="definition" :data="insightData" :loading="insightLoading" :error="insightError" :ticket-url="ticketSearchUrl" :asset-url="assetSearchUrl" :licence-url="licenceSearchUrl" :change-url="changeSearchUrl" :problem-url="problemSearchUrl" />
    <div v-if="definition" ref="gridElement" class="marifex-widget-grid" :class="{ 'marifex-widget-grid--editing': editing }">
      <WidgetCard v-for="widget in definition.widgets" :key="widget.id" :widget="widget" :section-label="sectionLabels[widget.id]" :data="dataFor(widget)" :movement="insightFor(widget)" :indicator="indicatorFor(widget)" :loading="loading" :editing="editing" :interacting="interactingWidget === widget.id" :interaction-mode="interactingWidget === widget.id ? interactionMode : null" :selected-group="selectedGroup" :ticket-search-url="ticketSearchUrl" :asset-search-url="assetSearchUrl" :licence-search-url="licenceSearchUrl" :change-search-url="changeSearchUrl" :problem-search-url="problemSearchUrl" @remove="removeWidget" @rename="renameWidget" @palette="recolorWidget" @select-group="chooseGroup" @interaction-start="beginInteraction" />
    </div>

    <div v-if="catalogOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="catalogOpen = false"><aside class="marifex-catalog" role="dialog" aria-modal="true" aria-labelledby="catalog-title"><header><div><p class="marifex-command__eyebrow">Certified semantic layer</p><h2 id="catalog-title">Widget library</h2></div><button class="btn-close" type="button" aria-label="Close" @click="catalogOpen = false"></button></header><p class="text-secondary">Every widget uses an approved metric. SQL and unrestricted data access are never accepted.</p><div class="marifex-catalog__grid"><button v-for="item in catalog" :key="`${item.metric}-${item.type}`" class="card marifex-catalog-item" type="button" @click="addWidget(item)"><span class="badge bg-azure-lt">{{ item.type }}</span><strong>{{ item.title }}</strong><small>{{ item.metric.replaceAll('_', ' ') }}</small></button></div></aside></div>

    <div v-if="templateOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="templateOpen = false"><aside class="marifex-catalog" role="dialog" aria-modal="true" aria-labelledby="template-title"><header><div><p class="marifex-command__eyebrow">Dashboard templates</p><h2 id="template-title">Create dashboard</h2></div><button class="btn-close" type="button" aria-label="Close" @click="templateOpen = false"></button></header><div class="mt-3"><label class="form-label" for="marifex-new-dashboard-name">Dashboard name</label><input id="marifex-new-dashboard-name" v-model="newDashboardName" class="form-control" maxlength="120"></div><div class="marifex-template-grid"><button v-for="template in templates" :key="template.key" class="card marifex-template-item" :class="{ 'is-selected': selectedTemplate === template.key }" type="button" @click="chooseTemplate(template)"><strong>{{ template.name }}</strong><small>{{ template.description }}</small></button></div><button class="btn btn-primary w-100 mt-3" type="button" :disabled="saving" @click="createDashboard">Create from template</button></aside></div>

    <div v-if="scheduleOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="scheduleOpen = false">
      <aside class="marifex-catalog marifex-report-scheduler" role="dialog" aria-modal="true" aria-labelledby="schedule-title">
        <header><div><p class="marifex-command__eyebrow">Governed delivery</p><h2 id="schedule-title">Schedule dashboard report</h2></div><button class="btn-close" type="button" aria-label="Close" @click="scheduleOpen = false"></button></header>
        <div v-if="scheduleError" class="alert alert-danger mt-3">{{ scheduleError }}</div>
        <div class="marifex-schedule-form mt-3">
          <div><label class="form-label" for="report-name">Schedule name</label><input id="report-name" v-model.trim="scheduleForm.name" class="form-control" maxlength="120"></div>
          <div><label class="form-label" for="report-format">Format</label><select id="report-format" v-model="scheduleForm.format" class="form-select"><option value="pdf">PDF</option><option value="csv">CSV</option></select></div>
          <div><label class="form-label" for="report-frequency">Frequency</label><select id="report-frequency" v-model="scheduleForm.frequency" class="form-select"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div>
          <div><label class="form-label" for="report-hour">Delivery hour</label><select id="report-hour" v-model.number="scheduleForm.send_hour" class="form-select"><option v-for="hour in 24" :key="hour - 1" :value="hour - 1">{{ String(hour - 1).padStart(2, '0') }}:00</option></select></div>
          <div v-if="scheduleForm.frequency === 'weekly'"><label class="form-label" for="report-weekday">Weekday</label><select id="report-weekday" v-model.number="scheduleForm.weekday" class="form-select"><option :value="1">Monday</option><option :value="2">Tuesday</option><option :value="3">Wednesday</option><option :value="4">Thursday</option><option :value="5">Friday</option><option :value="6">Saturday</option><option :value="7">Sunday</option></select></div>
          <div v-if="scheduleForm.frequency === 'monthly'"><label class="form-label" for="report-monthday">Day of month</label><select id="report-monthday" v-model.number="scheduleForm.monthday" class="form-select"><option v-for="day in 28" :key="day" :value="day">{{ day }}</option></select></div>
          <div><label class="form-label" for="report-timezone">Timezone</label><select id="report-timezone" v-model="scheduleForm.timezone" class="form-select"><option value="UTC">UTC</option><option v-if="scheduleForm.timezone !== 'UTC'" :value="scheduleForm.timezone">{{ scheduleForm.timezone }}</option></select></div>
          <div class="marifex-schedule-form__wide"><label class="form-label" for="report-recipients">GLPI recipient emails</label><input id="report-recipients" v-model="scheduleForm.recipients" class="form-control" placeholder="user@example.com, manager@example.com"><div class="form-hint">Recipients must be active GLPI users with dashboard access to this entity.</div></div>
        </div>
        <button class="btn btn-primary w-100 mt-3" type="button" @click="createSchedule">Create schedule</button>
        <div v-if="dashboardSchedules.length" class="marifex-schedule-list mt-4"><h3>Existing schedules</h3><div v-for="item in dashboardSchedules" :key="item.id" class="card"><div><strong>{{ item.name }}</strong><small>{{ item.frequency }} at {{ String(item.send_hour).padStart(2, '0') }}:00 {{ item.timezone }} - next {{ item.next_run_at }}</small></div><button class="btn btn-sm" :class="item.is_active ? 'btn-outline-warning' : 'btn-outline-success'" type="button" @click="toggleSchedule(item)">{{ item.is_active ? 'Pause' : 'Enable' }}</button><button class="btn btn-sm btn-outline-danger" type="button" @click="deleteSchedule(item)">Delete</button></div></div>
      </aside>
    </div>
  </section>
</template>
