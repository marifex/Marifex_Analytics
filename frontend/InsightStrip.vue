<script setup lang="ts">
import { computed, ref } from 'vue';
import type { InsightItem, InsightResponse } from './types';

const props = defineProps<{ data: InsightResponse | null; loading: boolean; error: string; ticketUrl: string; assetUrl: string; licenceUrl: string; changeUrl: string; problemUrl: string }>();
const expanded = ref(false);
const inspected = ref<string | null>(null);
const inspectedInsight = computed(() => props.data?.insights.find(item => item.key === inspected.value) ?? null);
const governedSuppressions = computed(() => (props.data?.suppressed ?? []).filter(item => item.code !== 'NO_MATERIAL_CHANGE').slice(0, 4));
function evidenceUrl(item: InsightItem): string {
  return { ticket: props.ticketUrl, asset: props.assetUrl, licence: props.licenceUrl, change: props.changeUrl, problem: props.problemUrl }[item.evidence_target];
}
function format(value: number, unit: string): string { return unit === 'percent' ? `${value.toFixed(1)}%` : `${value.toLocaleString()} ${unit}`; }
</script>

<template>
  <section class="marifex-insight-strip" :class="{ 'is-expanded': expanded }" aria-labelledby="marifex-insight-summary">
    <button class="marifex-insight-strip__summary" type="button" :aria-expanded="expanded" @click="expanded = !expanded">
      <span class="marifex-insight-strip__badge">Insights</span>
      <strong id="marifex-insight-summary">{{ loading ? 'Calculating certified movements...' : error || data?.summary || 'No analytical insight is available.' }}</strong>
      <small v-if="data">{{ data.readiness.ready_metrics }}/{{ data.readiness.total_metrics }} comparison sources ready</small>
      <span aria-hidden="true">{{ expanded ? '−' : '+' }}</span>
    </button>
    <div v-if="expanded" class="marifex-insight-strip__body">
      <article v-for="item in data?.insights ?? []" :key="item.key" class="marifex-insight-finding" :class="`is-${item.direction}`">
        <span class="marifex-insight-finding__state">{{ item.direction }}</span>
        <div><strong>{{ item.label }}</strong><p>{{ item.narrative }}</p><small>Snapshot as of {{ item.as_of || data?.cutoff }} · {{ data?.formula_version }}</small></div>
        <div class="marifex-insight-finding__actions"><a :href="evidenceUrl(item)">Open evidence</a><button type="button" @click="inspected = item.key">Calculation</button></div>
      </article>
      <div v-if="governedSuppressions.length && data?.insights.length" class="marifex-insight-suppressions"><strong>Suppressed calculations</strong><span v-for="item in governedSuppressions" :key="item.key"><code>{{ item.code }}</code> {{ item.message }}</span></div>
      <div v-if="!(data?.insights.length)" class="marifex-insight-readiness">
        <strong>{{ data?.summary || error }}</strong>
        <span v-for="item in data?.readiness.metrics.filter(metric => !metric.ready).slice(0, 5) ?? []" :key="item.metric">{{ item.metric }}: {{ item.completed }} of {{ item.required }} snapshots ({{ item.state }})</span>
      </div>
    </div>
  </section>

  <div v-if="inspectedInsight" class="marifex-catalog-backdrop" role="presentation" @click.self="inspected = null">
    <aside class="marifex-catalog marifex-calculation-panel" role="dialog" aria-modal="true" aria-labelledby="marifex-calculation-title">
      <header><div><p class="marifex-command__eyebrow">Governed calculation</p><h2 id="marifex-calculation-title">{{ inspectedInsight.label }}</h2></div><button class="btn-close" type="button" aria-label="Close calculation" @click="inspected = null"></button></header>
      <dl>
        <div><dt>Formula version</dt><dd>{{ inspectedInsight.calculation.formula_version }}</dd></div>
        <div><dt>Formula</dt><dd><code>{{ inspectedInsight.calculation.formula }}</code></dd></div>
        <div><dt>Current result</dt><dd>{{ format(inspectedInsight.current, inspectedInsight.unit) }}</dd></div>
        <div><dt>Previous result</dt><dd>{{ format(inspectedInsight.previous, inspectedInsight.unit) }}</dd></div>
        <div v-if="inspectedInsight.calculation.current_numerator !== undefined"><dt>Current numerator</dt><dd>{{ inspectedInsight.calculation.current_numerator }}</dd></div>
        <div v-if="inspectedInsight.calculation.current_denominator !== undefined"><dt>Current denominator</dt><dd>{{ inspectedInsight.calculation.current_denominator }}</dd></div>
        <div v-if="inspectedInsight.calculation.previous_numerator !== undefined"><dt>Previous numerator</dt><dd>{{ inspectedInsight.calculation.previous_numerator }}</dd></div>
        <div v-if="inspectedInsight.calculation.previous_denominator !== undefined"><dt>Previous denominator</dt><dd>{{ inspectedInsight.calculation.previous_denominator }}</dd></div>
        <div><dt>Materiality gates</dt><dd>{{ inspectedInsight.calculation.absolute_gate }} native units and {{ inspectedInsight.calculation.relative_gate_percent }}%</dd></div>
        <div><dt>Comparison</dt><dd>{{ inspectedInsight.comparison_text }}</dd></div>
      </dl>
    </aside>
  </div>
</template>
