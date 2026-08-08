<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import WidgetCard from './WidgetCard.vue';
import type { DashboardTemplate, DashboardWorkspace, MetricResponse, SavedDashboard, WidgetDefinition } from './types';

const props = defineProps<{ metricEndpoint: string; definitionEndpoint: string; csrfToken: string; ticketSearchUrl: string; assetSearchUrl: string; licenceSearchUrl: string; changeSearchUrl: string; problemSearchUrl: string }>();
const dashboard = ref<SavedDashboard | null>(null);
const dashboards = ref<DashboardWorkspace['dashboards']>([]);
const templates = ref<DashboardTemplate[]>([]);
const metrics = ref<Record<string, MetricResponse>>({});
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const editing = ref(false);
const catalogOpen = ref(false);
const templateOpen = ref(false);
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

const catalog: Array<Omit<WidgetDefinition, 'id'>> = [
  { metric: 'current_open_tickets', type: 'kpi', title: 'Open now', w: 3, h: 2 },
  { metric: 'average_open_ticket_age', type: 'kpi', title: 'Average ticket age', w: 3, h: 2 },
  { metric: 'average_open_ticket_age', type: 'line', title: 'Ticket age trajectory', w: 6, h: 4 },
  { metric: 'historical_open_backlog', type: 'line', title: 'Enterprise backlog trajectory', w: 6, h: 4 },
  { metric: 'historical_open_backlog', type: 'bar', title: 'Backlog by day', w: 6, h: 4 },
  { metric: 'historical_group_backlog', type: 'donut', title: 'Workload concentration', w: 5, h: 4 },
  { metric: 'historical_group_backlog', type: 'bar', title: 'Group workload comparison', w: 6, h: 4 },
  { metric: 'historical_group_backlog', type: 'table', title: 'Service ownership ranking', w: 7, h: 4 },
  { metric: 'asset_inventory_total', type: 'kpi', title: 'Managed computers', w: 3, h: 2 },
  { metric: 'asset_inventory_by_state', type: 'donut', title: 'Computer lifecycle distribution', w: 5, h: 4 },
  { metric: 'stale_computer_inventory', type: 'kpi', title: 'Inventory stale over 30 days', w: 3, h: 2 },
  { metric: 'software_license_entitlements', type: 'kpi', title: 'Licence entitlements', w: 3, h: 2 },
  { metric: 'software_license_allocations', type: 'kpi', title: 'Allocated licence seats', w: 3, h: 2 },
  { metric: 'software_license_overallocated_seats', type: 'kpi', title: 'Overallocated seats', w: 3, h: 2 },
  { metric: 'software_license_compliance_rate', type: 'kpi', title: 'Licence compliance', w: 3, h: 2 },
  { metric: 'open_changes', type: 'kpi', title: 'Open changes', w: 4, h: 2 },
  { metric: 'daily_change_volume', type: 'line', title: 'Change demand trajectory', w: 7, h: 4 },
  { metric: 'daily_change_resolutions', type: 'line', title: 'Change resolution trajectory', w: 7, h: 4 },
  { metric: 'open_change_status_distribution', type: 'donut', title: 'Open changes by status', w: 5, h: 4 },
  { metric: 'open_problems', type: 'kpi', title: 'Open problems', w: 4, h: 2 },
  { metric: 'daily_problem_volume', type: 'line', title: 'Problem demand trajectory', w: 7, h: 4 },
  { metric: 'daily_problem_resolutions', type: 'line', title: 'Problem resolution trajectory', w: 7, h: 4 },
  { metric: 'open_problem_status_distribution', type: 'donut', title: 'Open problems by status', w: 5, h: 4 },
];
const definition = computed(() => dashboard.value?.definition);
const hasGroupFilter = computed(() => definition.value?.widgets.some(widget => supportsGroup(widget.metric)) ?? false);
const layoutPositions = computed(() => {
  const positions: Record<string, { x: number; y: number }> = {};
  let x = 1; let y = 1; let rowHeight = 0;
  for (const widget of definition.value?.widgets ?? []) {
    if (x + widget.w - 1 > 12) { y += rowHeight; x = 1; rowHeight = 0; }
    positions[widget.id] = { x, y };
    x += widget.w; rowHeight = Math.max(rowHeight, widget.h);
  }
  return positions;
});
const groups = computed(() => {
  const groupMetric = metrics.value[metricKey('historical_group_backlog', null)];
  const points = (groupMetric?.series ?? []) as Array<{ dimension_id?: number; dimension?: string }>;
  const unique = new Map<number, string>();
  points.forEach(point => { if (point.dimension_id && point.dimension) unique.set(point.dimension_id, point.dimension); });
  return [...unique.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
});
const selectedGroupName = computed(() => groups.value.find(group => group.id === selectedGroup.value)?.name);

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
  } catch { error.value = 'The analytics dashboard could not be loaded. Check the plugin automatic actions and try again.'; }
  finally { loading.value = false; }
}
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
}
async function applyFilters(): Promise<void> {
  if (definition.value) definition.value.filters.groupId = selectedGroup.value;
  loading.value = true; error.value = '';
  try { await loadMetrics(); } catch { error.value = 'The selected dashboard filters could not be applied.'; }
  finally { loading.value = false; }
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
function addWidget(item: Omit<WidgetDefinition, 'id'>): void {
  if (!definition.value || definition.value.widgets.length >= 24) return;
  definition.value.widgets.push({ ...item, id: `widget-${Date.now().toString(36)}` }); catalogOpen.value = false; if (!editing.value) startEditing(); void loadMetrics();
}
function removeWidget(id: string): void { if (definition.value && definition.value.widgets.length > 1) definition.value.widgets = definition.value.widgets.filter(widget => widget.id !== id); }
function renameWidget(id: string, title: string): void { const widget = definition.value?.widgets.find(item => item.id === id); const clean = title.trim(); if (widget && clean) widget.title = clean; }
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
    widget.w = Math.max(3, Math.min(12, interaction.startW + Math.round((event.clientX - interaction.startX) / (columnWidth + gap))));
    widget.h = Math.max(2, Math.min(8, interaction.startH + Math.round((event.clientY - interaction.startY) / (rowHeight + gap))));
    return;
  }
  if (dragGhost) dragGhost.style.transform = `translate3d(${event.clientX - interaction.startX}px, ${event.clientY - interaction.startY}px, 0)`;
  const cards = Array.from(gridElement.value.querySelectorAll<HTMLElement>('.marifex-widget:not(.marifex-widget--dragging)'));
  if (!cards.length) return;
  const target = cards.reduce<{ element: HTMLElement; distance: number } | null>((closest, element) => {
    const box = element.getBoundingClientRect();
    const distance = Math.hypot(event.clientX - (box.left + box.width / 2), event.clientY - (box.top + box.height / 2));
    return !closest || distance < closest.distance ? { element, distance } : closest;
  }, null)?.element;
  const targetId = target?.dataset.widgetId; if (!targetId || targetId === interaction.id) return;
  const from = definition.value.widgets.findIndex(item => item.id === interaction!.id);
  let to = definition.value.widgets.findIndex(item => item.id === targetId);
  if (from < 0 || to < 0) return;
  const targetBox = target!.getBoundingClientRect();
  const placeAfter = event.clientY > targetBox.top + targetBox.height / 2 || (Math.abs(event.clientY - (targetBox.top + targetBox.height / 2)) < targetBox.height * .2 && event.clientX > targetBox.left + targetBox.width / 2);
  const [moved] = definition.value.widgets.splice(from, 1);
  if (from < to) to -= 1;
  definition.value.widgets.splice(to + (placeAfter ? 1 : 0), 0, moved);
}
function endInteraction(event?: PointerEvent): void {
  if (!interaction || (event && event.pointerId !== interaction.pointerId)) return;
  dragGhost?.remove(); dragGhost = null;
  interaction = null; interactingWidget.value = null; interactionMode.value = null;
  document.body.classList.remove('marifex-layout-interacting');
}
async function chooseGroup(id: number | null): Promise<void> { selectedGroup.value = selectedGroup.value === id ? null : id; await applyFilters(); }
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
        <button class="btn btn-outline-secondary" type="button" :disabled="loading" @click="loadMetrics">Refresh</button>
        <button v-if="!editing" class="btn btn-outline-primary" type="button" title="Edit dashboard layout" @click="startEditing"><svg aria-hidden="true" class="marifex-button-icon" viewBox="0 0 24 24"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Zm10-13 4 4"/></svg>Edit layout</button>
        <template v-else><button class="btn btn-success" type="button" :disabled="saving" @click="save">{{ saving ? 'Saving...' : 'Save dashboard' }}</button><button class="btn btn-outline-secondary" type="button" @click="cancelEditing">Cancel</button></template>
      </div>
    </header>

    <div v-if="editing && dashboard" class="card marifex-builderbar">
      <div><label class="form-label" for="marifex-dashboard-name">Dashboard name</label><input id="marifex-dashboard-name" v-model.trim="dashboard.name" class="form-control" maxlength="120"></div>
      <button class="btn btn-outline-primary" type="button" @click="catalogOpen = true">Add widget</button>
      <button v-if="dashboard.id" class="btn btn-outline-danger" type="button" @click="deleteDashboard">Delete dashboard</button>
      <small>Drag a card by its header to position it. Drag the corner grip to resize it; nearby widgets and chart content adjust automatically.</small>
    </div>

    <div v-if="definition" class="card marifex-filterbar">
      <div><label class="form-label" for="marifex-range">Dashboard horizon</label><select id="marifex-range" v-model.number="definition.dateRangeDays" class="form-select" @change="applyFilters"><option :value="7">7 days</option><option :value="30">30 days</option><option :value="90">90 days</option><option :value="180">180 days</option><option :value="365">365 days</option></select></div>
      <div v-if="hasGroupFilter"><label class="form-label" for="marifex-group">Assigned group</label><select id="marifex-group" v-model="selectedGroup" class="form-select" @change="applyFilters"><option :value="null">All service groups</option><option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option></select></div>
      <div><label class="form-label" for="marifex-refresh">Auto-refresh</label><select id="marifex-refresh" v-model.number="definition.refreshMinutes" class="form-select" @change="scheduleRefresh"><option :value="0">Manual</option><option :value="5">Every 5 minutes</option><option :value="15">Every 15 minutes</option><option :value="30">Every 30 minutes</option><option :value="60">Every hour</option></select></div>
      <div class="marifex-filterbar__status"><span class="marifex-pulse"></span><div><strong>Analytics current</strong><small>{{ selectedGroupName ? `Focused on ${selectedGroupName}` : 'Active GLPI entity scope' }}</small></div></div>
      <button v-if="hasGroupFilter && selectedGroup" class="btn btn-sm btn-ghost-secondary" type="button" @click="chooseGroup(null)">Clear group focus</button>
    </div>

    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="definition" ref="gridElement" class="marifex-widget-grid" :class="{ 'marifex-widget-grid--editing': editing }">
      <WidgetCard v-for="widget in definition.widgets" :key="widget.id" :widget="widget" :grid-x="layoutPositions[widget.id]?.x ?? 1" :grid-y="layoutPositions[widget.id]?.y ?? 1" :data="dataFor(widget)" :loading="loading" :editing="editing" :interacting="interactingWidget === widget.id" :interaction-mode="interactingWidget === widget.id ? interactionMode : null" :selected-group="selectedGroup" :ticket-search-url="ticketSearchUrl" :asset-search-url="assetSearchUrl" :licence-search-url="licenceSearchUrl" :change-search-url="changeSearchUrl" :problem-search-url="problemSearchUrl" @remove="removeWidget" @rename="renameWidget" @select-group="chooseGroup" @interaction-start="beginInteraction" />
    </div>

    <div v-if="catalogOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="catalogOpen = false"><aside class="marifex-catalog" role="dialog" aria-modal="true" aria-labelledby="catalog-title"><header><div><p class="marifex-command__eyebrow">Certified semantic layer</p><h2 id="catalog-title">Widget library</h2></div><button class="btn-close" type="button" aria-label="Close" @click="catalogOpen = false"></button></header><p class="text-secondary">Every widget uses an approved metric. SQL and unrestricted data access are never accepted.</p><div class="marifex-catalog__grid"><button v-for="item in catalog" :key="`${item.metric}-${item.type}`" class="card marifex-catalog-item" type="button" @click="addWidget(item)"><span class="badge bg-azure-lt">{{ item.type }}</span><strong>{{ item.title }}</strong><small>{{ item.metric.replaceAll('_', ' ') }}</small></button></div></aside></div>

    <div v-if="templateOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="templateOpen = false"><aside class="marifex-catalog" role="dialog" aria-modal="true" aria-labelledby="template-title"><header><div><p class="marifex-command__eyebrow">Dashboard templates</p><h2 id="template-title">Create dashboard</h2></div><button class="btn-close" type="button" aria-label="Close" @click="templateOpen = false"></button></header><div class="mt-3"><label class="form-label" for="marifex-new-dashboard-name">Dashboard name</label><input id="marifex-new-dashboard-name" v-model="newDashboardName" class="form-control" maxlength="120"></div><div class="marifex-template-grid"><button v-for="template in templates" :key="template.key" class="card marifex-template-item" :class="{ 'is-selected': selectedTemplate === template.key }" type="button" @click="chooseTemplate(template)"><strong>{{ template.name }}</strong><small>{{ template.description }}</small></button></div><button class="btn btn-primary w-100 mt-3" type="button" :disabled="saving" @click="createDashboard">Create from template</button></aside></div>
  </section>
</template>
