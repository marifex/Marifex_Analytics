<script setup lang="ts">
import { computed, ref } from 'vue';
import type { InsightItem, InsightResponse, ObservedMovement, ReadinessMetric } from './types';

const props = defineProps<{ data: InsightResponse | null; loading: boolean; error: string; ticketUrl: string; assetUrl: string; licenceUrl: string; changeUrl: string; problemUrl: string }>();
const expanded = ref(false);
const inspected = ref<string | null>(null);
const inspectedInsight = computed<InsightItem | ObservedMovement | null>(() => {
  if (inspected.value?.startsWith('observed:')) return props.data?.observed_movements.find(item => item.metric === inspected.value?.slice(9)) ?? null;
  return props.data?.insights.find(item => item.key === inspected.value) ?? null;
});
const governedSuppressions = computed(() => (props.data?.suppressed ?? []).filter(item => item.code !== 'NO_MATERIAL_CHANGE').slice(0, 4));
const notReadyMetrics = computed(() => props.data?.readiness.metrics.filter(metric => !metric.ready) ?? []);
const baselineMode = computed(() => Boolean(props.data && !props.data.insights.length && notReadyMetrics.value.length));
const metricLabels: Record<string, string> = {
  historical_open_backlog: 'Open backlog',
  created_vs_resolved_tickets: 'Created versus resolved tickets',
  unassigned_open_tickets: 'Unassigned tickets',
  tickets_approaching_sla_breach: 'Tickets approaching SLA breach',
  sla_breach_count: 'SLA breaches',
  open_tickets_by_priority: 'Open tickets by priority',
  historical_group_backlog: 'Assignment-group backlog',
  tickets_by_request_source: 'Request-source distribution',
  technician_workload_distribution: 'Technician workload',
  sla_breaches_by_technician: 'SLA breaches by technician',
  stale_computer_inventory: 'Stale computer inventory',
  asset_inventory_total: 'Managed computer inventory',
  repeat_incident_computers: 'Computers with repeated incidents',
  software_license_overallocated_seats: 'Overallocated licence seats',
  software_license_compliance_rate: 'Licence compliance',
  daily_change_volume: 'Changes raised',
  daily_change_resolutions: 'Changes resolved',
  daily_problem_volume: 'Problems raised',
  daily_problem_resolutions: 'Problems resolved',
};
const baselineCompleted = computed(() => notReadyMetrics.value.length ? Math.min(...notReadyMetrics.value.map(metric => metric.completed)) : 0);
const baselineRequired = computed(() => props.data?.readiness.required_snapshots ?? 0);
const baselineSummary = computed(() => `${props.data?.horizon_days ?? ''}-day trend analysis is preparing`);
const baselineMeta = computed(() => `${baselineCompleted.value} of ${baselineRequired.value} days available · ${notReadyMetrics.value.length} measure${notReadyMetrics.value.length === 1 ? '' : 's'} awaiting update`);
function sourceLabel(metric: string): string { return metricLabels[metric] ?? metric.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
function stateLabel(item: ReadinessMetric): string {
  if (item.state !== 'current') return ({ stale: 'Latest daily update pending', missing: 'Historical data unavailable', unavailable: 'Historical data unavailable' } as Record<string, string>)[item.state] ?? 'Historical data unavailable';
  return item.comparison_basis || 'Current value';
}
function evidenceUrl(item: InsightItem): string {
  if (item.key === 'top_group_workload_share' && item.contributor && item.contributor.dimension_id > 0) {
    return `${props.ticketUrl}?${new URLSearchParams({ group_id: String(item.contributor.dimension_id) })}`;
  }
  return { ticket: props.ticketUrl, asset: props.assetUrl, licence: props.licenceUrl, change: props.changeUrl, problem: props.problemUrl }[item.evidence_target];
}
function format(value: number, unit: string): string { return unit === 'percent' ? `${value.toFixed(1)}%` : `${value.toLocaleString()} ${unit}`; }
</script>

<template>
  <section class="marifex-insight-strip" :class="{ 'is-expanded': expanded }" aria-labelledby="marifex-insight-summary">
    <button class="marifex-insight-strip__summary" type="button" :aria-expanded="expanded" @click="expanded = !expanded">
      <span class="marifex-insight-strip__badge">{{ baselineMode ? 'Data status' : 'Insights' }}</span>
      <strong id="marifex-insight-summary">{{ loading ? 'Calculating certified movements...' : error || (baselineMode ? baselineSummary : data?.summary) || 'No analytical insight is available.' }}</strong>
      <small v-if="data">{{ baselineMode ? baselineMeta : `${data.readiness.ready_metrics}/${data.readiness.total_metrics} comparison sources ready` }}</small>
      <span aria-hidden="true">{{ expanded ? '−' : '+' }}</span>
    </button>
    <div v-if="expanded" class="marifex-insight-strip__body">
      <article v-for="item in data?.insights ?? []" :key="item.key" class="marifex-insight-finding" :class="`is-${item.direction}`">
        <span class="marifex-insight-finding__state">{{ item.direction }}</span>
        <div><strong>{{ item.label }}</strong><p>{{ item.narrative }}</p><small>Snapshot as of {{ item.as_of || data?.cutoff }} · {{ item.calculation.formula_version || data?.formula_version }}</small></div>
        <div class="marifex-insight-finding__actions"><a :href="evidenceUrl(item)">Open evidence</a><button type="button" @click="inspected = item.key">Calculation</button></div>
      </article>
      <article v-for="item in data?.observed_movements ?? []" :key="`observed-${item.metric}`" class="marifex-insight-finding is-neutral">
        <span class="marifex-insight-finding__state">Observed</span>
        <div><strong>{{ item.label }}</strong><p>{{ item.absolute_change >= 0 ? 'Increased' : 'Decreased' }} by {{ Math.abs(item.absolute_change).toLocaleString() }} since monitoring began.</p><small>Baseline {{ item.monitoring_baseline_at }} · {{ item.effective_provenance_label }}</small></div>
        <div class="marifex-insight-finding__actions"><button type="button" @click="inspected = `observed:${item.metric}`">Calculation</button></div>
      </article>
      <div v-if="governedSuppressions.length && !baselineMode" class="marifex-insight-suppressions"><strong>Suppressed calculations</strong><span v-for="item in governedSuppressions" :key="item.key"><code>{{ item.code }}</code> {{ item.message }}</span></div>
      <div v-if="!(data?.insights.length)" class="marifex-insight-readiness">
        <div class="marifex-insight-readiness__intro"><strong>{{ baselineMode ? baselineSummary : data?.summary || error }}</strong><span v-if="baselineMode">Historical data is available for {{ baselineCompleted }} of the required {{ baselineRequired }} days. Trends and period comparisons will appear after the next daily update.</span></div>
        <div v-if="baselineMode" class="marifex-insight-readiness__sources" aria-label="Measures awaiting update">
          <span v-for="item in notReadyMetrics" :key="item.metric"><strong>{{ sourceLabel(item.metric) }}</strong><small>{{ item.completed }} of {{ item.required }} days available · {{ stateLabel(item) }}</small></span>
        </div>
      </div>
    </div>
  </section>

  <div v-if="inspectedInsight" class="marifex-catalog-backdrop" role="presentation" @click.self="inspected = null">
    <aside class="marifex-catalog marifex-calculation-panel" role="dialog" aria-modal="true" aria-labelledby="marifex-calculation-title">
      <header><div><p class="marifex-command__eyebrow">Governed calculation</p><h2 id="marifex-calculation-title">{{ inspectedInsight.label }}</h2></div><button class="btn-close" type="button" aria-label="Close calculation" @click="inspected = null"></button></header>
      <dl>
        <div v-if="'calculation' in inspectedInsight"><dt>Formula version</dt><dd>{{ inspectedInsight.calculation.formula_version }}</dd></div>
        <div v-if="'calculation' in inspectedInsight"><dt>Formula</dt><dd><code>{{ inspectedInsight.calculation.formula }}</code></dd></div>
        <div><dt>Current result</dt><dd>{{ 'unit' in inspectedInsight ? format(inspectedInsight.current, inspectedInsight.unit) : inspectedInsight.current.toLocaleString() }}</dd></div>
        <div v-if="'previous' in inspectedInsight"><dt>Previous result</dt><dd>{{ format(inspectedInsight.previous, inspectedInsight.unit) }}</dd></div>
        <div v-if="'baseline' in inspectedInsight"><dt>Monitoring baseline</dt><dd>{{ inspectedInsight.baseline.toLocaleString() }} as of {{ inspectedInsight.monitoring_baseline_at }}</dd></div>
        <template v-if="'calculation' in inspectedInsight">
          <div v-if="inspectedInsight.calculation.current_numerator !== undefined"><dt>Current numerator</dt><dd>{{ inspectedInsight.calculation.current_numerator }}</dd></div>
          <div v-if="inspectedInsight.calculation.current_denominator !== undefined"><dt>Current denominator</dt><dd>{{ inspectedInsight.calculation.current_denominator }}</dd></div>
          <div v-if="inspectedInsight.calculation.previous_numerator !== undefined"><dt>Previous numerator</dt><dd>{{ inspectedInsight.calculation.previous_numerator }}</dd></div>
          <div v-if="inspectedInsight.calculation.previous_denominator !== undefined"><dt>Previous denominator</dt><dd>{{ inspectedInsight.calculation.previous_denominator }}</dd></div>
          <div><dt>Materiality gates</dt><dd>{{ inspectedInsight.calculation.absolute_gate }} native units and {{ inspectedInsight.calculation.relative_gate_percent }}% · {{ inspectedInsight.calculation.materiality_outcome }}</dd></div>
        </template>
        <div><dt>Activation</dt><dd>{{ inspectedInsight.activation_state }}</dd></div>
        <div><dt>Comparison basis</dt><dd>{{ inspectedInsight.comparison_basis }}</dd></div>
        <div><dt>Provenance</dt><dd>{{ inspectedInsight.effective_provenance_label }}</dd></div>
        <div v-if="'calculation' in inspectedInsight && inspectedInsight.calculation.current_period"><dt>Current period</dt><dd>{{ inspectedInsight.calculation.current_period.from }} to {{ inspectedInsight.calculation.current_period.to }}</dd></div>
        <div v-if="'calculation' in inspectedInsight && inspectedInsight.calculation.previous_period"><dt>Previous period</dt><dd>{{ inspectedInsight.calculation.previous_period.from }} to {{ inspectedInsight.calculation.previous_period.to }}</dd></div>
        <div v-if="'calculation' in inspectedInsight"><dt>Coverage</dt><dd>{{ inspectedInsight.calculation.coverage?.filter(item => item.ready).length ?? 0 }} of {{ inspectedInsight.calculation.coverage?.length ?? 0 }} governed measures comparison-ready</dd></div>
        <div v-if="'calculation' in inspectedInsight && inspectedInsight.calculation.scope"><dt>Scope</dt><dd>Entity #{{ inspectedInsight.calculation.scope.root_entity_id }}{{ inspectedInsight.calculation.scope.recursive ? ' and descendants' : '' }}{{ inspectedInsight.calculation.scope.group_id ? ` · Group #${inspectedInsight.calculation.scope.group_id}` : '' }}</dd></div>
        <div v-if="'calculation' in inspectedInsight"><dt>Last refresh</dt><dd>{{ inspectedInsight.calculation.last_refresh || data?.cutoff }}</dd></div>
      </dl>
    </aside>
  </div>
</template>
