<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import WidgetCard from './WidgetCard.vue';
import type { MetricResponse, SavedDashboard, WidgetDefinition } from './types';

const props = defineProps<{ metricEndpoint: string; definitionEndpoint: string; csrfToken: string; ticketSearchUrl: string }>();
const dashboard = ref<SavedDashboard | null>(null);
const metrics = ref<Record<string, MetricResponse>>({});
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const editing = ref(false);
const catalogOpen = ref(false);
const selectedGroup = ref<number | null>(null);
const draggedWidget = ref<string | null>(null);

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
  const groupMetric = metrics.value[metricKey('historical_group_backlog:global')];
  const points = (groupMetric?.series ?? []) as Array<{ dimension_id?: number; dimension?: string }>;
  const unique = new Map<number, string>();
  points.forEach(point => { if (point.dimension_id && point.dimension) unique.set(point.dimension_id, point.dimension); });
  return [...unique.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
});
const selectedGroupName = computed(() => groups.value.find(group => group.id === selectedGroup.value)?.name);

function range(): { from: string; to: string } {
  const to = new Date(); const from = new Date(); from.setDate(from.getDate() - (definition.value?.dateRangeDays ?? 30));
  return { from: toDate(from), to: toDate(to) };
}
function toDate(date: Date): string { return date.toISOString().slice(0, 10); }
function metricKey(metric: string): string { return `${metric}:${selectedGroup.value ?? 0}`; }
function supportsGroup(metric: string): boolean { return ['current_open_tickets', 'historical_open_backlog'].includes(metric); }
function dataFor(widget: WidgetDefinition): MetricResponse | undefined {
  return metrics.value[metricKey(supportsGroup(widget.metric) ? widget.metric : `${widget.metric}:global`)] ?? metrics.value[widget.metric];
}

async function load(): Promise<void> {
  loading.value = true; error.value = '';
  try {
    const response = await fetch(props.definitionEndpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Dashboard definition request failed');
    dashboard.value = await response.json();
    await loadMetrics();
  } catch { error.value = 'The executive dashboard could not be loaded. Check the plugin automatic actions and try again.'; }
  finally { loading.value = false; }
}
async function loadMetrics(): Promise<void> {
  if (!definition.value) return;
  const { from, to } = range();
  const requested = new Set(definition.value.widgets.map(widget => widget.metric));
  const entries = await Promise.all([...requested].map(async metric => {
    const params = new URLSearchParams({ from, to });
    if (selectedGroup.value && supportsGroup(metric)) params.set('group_id', String(selectedGroup.value));
    const response = await fetch(`${props.metricEndpoint}/${metric}?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Metric request failed');
    const key = supportsGroup(metric) ? metricKey(metric) : metricKey(`${metric}:global`);
    return [key, await response.json()] as const;
  }));
  metrics.value = { ...metrics.value, ...Object.fromEntries(entries) };
}
async function applyFilters(): Promise<void> { loading.value = true; try { await loadMetrics(); } finally { loading.value = false; } }
async function save(): Promise<void> {
  if (!dashboard.value) return;
  saving.value = true; error.value = '';
  try {
    const response = await fetch(props.definitionEndpoint, { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Glpi-Csrf-Token': props.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ name: dashboard.value.name, definition: dashboard.value.definition }) });
    if (!response.ok) throw new Error('Save failed');
    dashboard.value = await response.json(); editing.value = false;
  } catch { error.value = 'The layout could not be saved. Refresh the page and try again.'; }
  finally { saving.value = false; }
}
function addWidget(item: Omit<WidgetDefinition, 'id'>): void {
  if (!definition.value || definition.value.widgets.length >= 24) return;
  definition.value.widgets.push({ ...item, id: `widget-${Date.now().toString(36)}` }); catalogOpen.value = false; editing.value = true; void loadMetrics();
}
function removeWidget(id: string): void { if (definition.value && definition.value.widgets.length > 1) definition.value.widgets = definition.value.widgets.filter(widget => widget.id !== id); }
function moveWidget(id: string, direction: -1 | 1): void { if (!definition.value) return; const index = definition.value.widgets.findIndex(widget => widget.id === id); const target = index + direction; if (index >= 0 && target >= 0 && target < definition.value.widgets.length) [definition.value.widgets[index], definition.value.widgets[target]] = [definition.value.widgets[target], definition.value.widgets[index]]; }
function resizeWidget(id: string): void { const widget = definition.value?.widgets.find(item => item.id === id); if (widget) widget.w = widget.w >= 12 ? 3 : widget.w >= 8 ? 12 : widget.w >= 6 ? 8 : widget.w >= 4 ? 6 : 4; }
function renameWidget(id: string, title: string): void { const widget = definition.value?.widgets.find(item => item.id === id); const clean = title.trim(); if (widget && clean) widget.title = clean; }
function dropOn(targetId: string): void { if (!draggedWidget.value || draggedWidget.value === targetId || !definition.value) return; const from = definition.value.widgets.findIndex(w => w.id === draggedWidget.value); const to = definition.value.widgets.findIndex(w => w.id === targetId); const [widget] = definition.value.widgets.splice(from, 1); definition.value.widgets.splice(to, 0, widget); draggedWidget.value = null; }
async function chooseGroup(id: number | null): Promise<void> { selectedGroup.value = selectedGroup.value === id ? null : id; await applyFilters(); }
onMounted(() => void load());
</script>

<template>
  <section class="marifex-command" aria-labelledby="marifex-title">
    <header class="marifex-command__hero">
      <div><p class="marifex-command__eyebrow">MarifeX Intelligence</p><h1 id="marifex-title">{{ dashboard?.name ?? 'Executive Operations Command' }}</h1><p>Certified service intelligence for decisive operational leadership.</p></div>
      <div class="marifex-command__actions"><button class="btn btn-outline-secondary" type="button" @click="catalogOpen = true">Add widget</button><button class="btn" :class="editing ? 'btn-primary' : 'btn-outline-primary'" type="button" @click="editing = !editing">{{ editing ? 'Editing layout' : 'Edit layout' }}</button><button v-if="editing" class="btn btn-success" type="button" :disabled="saving" @click="save">{{ saving ? 'Saving...' : 'Save dashboard' }}</button></div>
    </header>

    <div v-if="definition" class="card marifex-filterbar">
      <div><label class="form-label" for="marifex-range">Executive horizon</label><select id="marifex-range" v-model.number="definition.dateRangeDays" class="form-select" @change="applyFilters"><option :value="7">7 days</option><option :value="30">30 days</option><option :value="90">90 days</option><option :value="180">180 days</option><option :value="365">365 days</option></select></div>
      <div><label class="form-label" for="marifex-group">Assigned group</label><select id="marifex-group" v-model="selectedGroup" class="form-select" @change="applyFilters"><option :value="null">All service groups</option><option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option></select></div>
      <div class="marifex-filterbar__status"><span class="marifex-pulse"></span><div><strong>Analytics current</strong><small>{{ selectedGroupName ? `Focused on ${selectedGroupName}` : 'Enterprise view across active entities' }}</small></div></div>
      <button v-if="selectedGroup" class="btn btn-sm btn-ghost-secondary" type="button" @click="chooseGroup(null)">Clear group focus</button>
    </div>

    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="definition" class="marifex-widget-grid" :class="{ 'marifex-widget-grid--editing': editing }">
      <WidgetCard v-for="widget in definition.widgets" :key="widget.id" :widget="widget" :data="dataFor(widget)" :loading="loading" :editing="editing" :selected-group="selectedGroup" :ticket-search-url="ticketSearchUrl" @remove="removeWidget" @move="moveWidget" @resize="resizeWidget" @rename="renameWidget" @select-group="chooseGroup" @dragstart="draggedWidget = widget.id" @dragover.prevent @drop="dropOn(widget.id)" />
    </div>

    <div v-if="catalogOpen" class="marifex-catalog-backdrop" role="presentation" @click.self="catalogOpen = false"><aside class="marifex-catalog" role="dialog" aria-modal="true" aria-labelledby="catalog-title"><header><div><p class="marifex-command__eyebrow">Curated intelligence</p><h2 id="catalog-title">Executive widget library</h2></div><button class="btn-close" type="button" aria-label="Close" @click="catalogOpen = false"></button></header><p class="text-secondary">Every widget uses a certified metric. Custom SQL and unrestricted data access are not available.</p><div class="marifex-catalog__grid"><button v-for="item in catalog" :key="`${item.metric}-${item.type}`" class="card marifex-catalog-item" type="button" @click="addWidget(item)"><span class="badge bg-azure-lt">{{ item.type }}</span><strong>{{ item.title }}</strong><small>{{ item.metric.replaceAll('_', ' ') }}</small></button></div></aside></div>
  </section>
</template>
