<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import WidgetCard from './WidgetCard.vue';
import type { DashboardTemplate, DashboardWorkspace, MetricResponse, SavedDashboard, WidgetDefinition } from './types';

const props = defineProps<{ metricEndpoint: string; definitionEndpoint: string; csrfToken: string; ticketSearchUrl: string }>();
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
const draggedWidget = ref<string | null>(null);
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
];
const definition = computed(() => dashboard.value?.definition);
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
  requests.set(metricKey('historical_group_backlog', null), { metric: 'historical_group_backlog', groupId: null });
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
function moveWidget(id: string, direction: -1 | 1): void { if (!definition.value) return; const index = definition.value.widgets.findIndex(widget => widget.id === id); const target = index + direction; if (index >= 0 && target >= 0 && target < definition.value.widgets.length) [definition.value.widgets[index], definition.value.widgets[target]] = [definition.value.widgets[target], definition.value.widgets[index]]; }
function resizeWidget(id: string, dimension: 'w' | 'h'): void {
  const widget = definition.value?.widgets.find(item => item.id === id); if (!widget) return;
  const sizes = dimension === 'w' ? [3, 4, 6, 8, 12] : [2, 3, 4, 5, 6, 8];
  const current = sizes.indexOf(widget[dimension]); widget[dimension] = sizes[(current + 1) % sizes.length];
}
function renameWidget(id: string, title: string): void { const widget = definition.value?.widgets.find(item => item.id === id); const clean = title.trim(); if (widget && clean) widget.title = clean; }
function dropOn(targetId: string): void { if (!draggedWidget.value || draggedWidget.value === targetId || !definition.value) return; const from = definition.value.widgets.findIndex(w => w.id === draggedWidget.value); const to = definition.value.widgets.findIndex(w => w.id === targetId); if (from < 0 || to < 0) return; const [widget] = definition.value.widgets.splice(from, 1); definition.value.widgets.splice(to, 0, widget); draggedWidget.value = null; }
async function chooseGroup(id: number | null): Promise<void> { selectedGroup.value = selectedGroup.value === id ? null : id; await applyFilters(); }
onMounted(() => void load());
onBeforeUnmount(() => { if (refreshTimer !== undefined) window.clearInterval(refreshTimer); });
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
        <button v-if="!editing" class="btn btn-outline-primary" type="button" @click="startEditing">Edit layout</button>
        <template v-else><button class="btn btn-success" type="button" :disabled="saving" @click="save">{{ saving ? 'Saving...' : 'Save dashboard' }}</button><button class="btn btn-outline-secondary" type="button" @click="cancelEditing">Cancel</button></template>
      </div>
    </header>

    <div v-if="editing && dashboard" class="card marifex-builderbar">
      <div><label class="form-label" for="marifex-dashboard-name">Dashboard name</label><input id="marifex-dashboard-name" v-model.trim="dashboard.name" class="form-control" maxlength="120"></div>
      <button class="btn btn-outline-primary" type="button" @click="catalogOpen = true">Add widget</button>
      <button v-if="dashboard.id" class="btn btn-outline-danger" type="button" @click="deleteDashboard">Delete dashboard</button>
      <small>Drag cards to reorder. Width and height controls resize each grid item.</small>
    </div>

    <div v-if="definition" class="card marifex-filterbar">
      <div><label class="form-label" for="marifex-range">Dashboard horizon</label><select id="marifex-range" v-model.number="definition.dateRangeDays" class="form-select" @change="applyFilters"><option :value="7">7 days</option><option :value="30">30 days</option><option :value="90">90 days</option><option :value="180">180 days</option><option :value="365">365 days</option></select></div>
      <div><label class="form-label" for="marifex-group">Assigned group</label><select id="marifex-group" v-model="selectedGroup" class="form-select" @change="applyFilters"><option :value="null">All service groups</option><option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option></select></div>
      <div><label class="form-label" for="marifex-refresh">Auto-refresh</label><select id="marifex-refresh" v-model.number="definition.refreshMinutes" class="form-select" @change="scheduleRefresh"><option :value="0">Manual</option><option :value="5">Every 5 minutes</option><option :value="15">Every 15 minutes</option><option :value="30">Every 30 minutes</option><option :value="60">Every hour</option></select></div>
      <div class="marifex-filterbar__status"><span class="marifex-pulse"></span><div><strong>Analytics current</strong><small>{{ selectedGroupName ? `Focused on ${selectedGroupName}` : 'Active GLPI entity scope' }}</small></div></div>
      <button v-if="selectedGroup" class="btn btn-sm btn-ghost-secondary" type="button" @click="chooseGroup(null)">Clear group focus</button>
    </div>

    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="definition" class="marifex-widget-grid" :class="{ 'marifex-widget-grid--editing': editing }">
      <WidgetCard v-for="widget in definition.widgets" :key="widget.id" :widget="widget" :data="dataFor(widget)" :loading="loading" :editing="editing" :selected-group="selectedGroup" :ticket-search-url="ticketSearchUrl" @remove="removeWidget" @move="moveWidget" @resize="resizeWidget" @rename="renameWidget" @select-group="chooseGroup" @dragstart="draggedWidget = widget.id" @dragend="draggedWidget = null" @dragover.prevent @drop="dropOn(widget.id)" />
    </div>

    <div v-if="catalogOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="catalogOpen = false"><aside class="marifex-catalog" role="dialog" aria-modal="true" aria-labelledby="catalog-title"><header><div><p class="marifex-command__eyebrow">Certified semantic layer</p><h2 id="catalog-title">Widget library</h2></div><button class="btn-close" type="button" aria-label="Close" @click="catalogOpen = false"></button></header><p class="text-secondary">Every widget uses an approved metric. SQL and unrestricted data access are never accepted.</p><div class="marifex-catalog__grid"><button v-for="item in catalog" :key="`${item.metric}-${item.type}`" class="card marifex-catalog-item" type="button" @click="addWidget(item)"><span class="badge bg-azure-lt">{{ item.type }}</span><strong>{{ item.title }}</strong><small>{{ item.metric.replaceAll('_', ' ') }}</small></button></div></aside></div>

    <div v-if="templateOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="templateOpen = false"><aside class="marifex-catalog" role="dialog" aria-modal="true" aria-labelledby="template-title"><header><div><p class="marifex-command__eyebrow">Dashboard templates</p><h2 id="template-title">Create dashboard</h2></div><button class="btn-close" type="button" aria-label="Close" @click="templateOpen = false"></button></header><div class="mt-3"><label class="form-label" for="marifex-new-dashboard-name">Dashboard name</label><input id="marifex-new-dashboard-name" v-model="newDashboardName" class="form-control" maxlength="120"></div><div class="marifex-template-grid"><button v-for="template in templates" :key="template.key" class="card marifex-template-item" :class="{ 'is-selected': selectedTemplate === template.key }" type="button" @click="chooseTemplate(template)"><strong>{{ template.name }}</strong><small>{{ template.description }}</small></button></div><button class="btn btn-primary w-100 mt-3" type="button" :disabled="saving" @click="createDashboard">Create from template</button></aside></div>
  </section>
</template>
