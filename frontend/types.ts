/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */
export type Point = { date: string; value: number; sample_count?: number };
export type DimensionPoint = Point & { dimension_id: number; dimension: string };
export type MetricResponse = {
  metric: string;
  label: string;
  source: 'live' | 'data_mart';
  value?: number;
  series?: Array<Point | DimensionPoint>;
  rows?: Array<Record<string, string | number>>;
  matrix?: Array<{ row_id: number; row: string; column_id: number; column: string; value: number }>;
  as_of?: string;
  provenance?: 'OBSERVED' | 'CERTIFIED_BOOTSTRAP';
  provenance_label?: string;
  effective_provenance?: 'OBSERVED' | 'CERTIFIED_BOOTSTRAP';
  effective_provenance_label?: string;
};
export type InsightCalculation = {
  formula_version: string;
  formula: string;
  current_numerator?: number;
  current_denominator?: number;
  previous_numerator?: number;
  previous_denominator?: number;
  absolute_gate: number;
  relative_gate_percent: number;
  materiality_outcome?: 'passed' | 'bypassed' | 'suppressed';
  result: number;
  indicator?: string;
  current_sample_count?: number;
  previous_sample_count?: number;
  contributors?: Array<{ dimension_id: number; label: string; delta: number }>;
  scope?: { root_entity_id: number; entity_ids: number[]; recursive: boolean; group_id: number | null };
  coverage?: ReadinessMetric[];
  last_refresh?: string | null;
  current_period?: { from: string; to: string };
  previous_period?: { from: string; to: string };
};
export type InsightItem = {
  key: string;
  label: string;
  direction: 'worsening' | 'improving' | 'neutral';
  unit: string;
  current: number;
  previous: number;
  absolute_change: number;
  relative_change_percent: number | null;
  percentage_point_change: number | null;
  materiality_score: number;
  comparison_text: string;
  activation_state: 'CERTIFIED_PERIOD_COMPARISON';
  comparison_basis: string;
  provenance: 'DERIVED';
  provenance_label: string;
  effective_provenance: 'OBSERVED' | 'CERTIFIED_BOOTSTRAP';
  effective_provenance_label: string;
  narrative: string;
  contributor?: { dimension_id: number; label: string; delta: number } | null;
  evidence_target: 'ticket' | 'asset' | 'licence' | 'change' | 'problem';
  source: 'data_mart';
  as_of: string | null;
  calculation: InsightCalculation;
};
export type ObservedMovement = {
  metric: string;
  label: string;
  current: number;
  baseline: number;
  absolute_change: number;
  monitoring_baseline_at: string;
  activation_state: 'OBSERVED_MOVEMENT';
  comparison_basis: 'Since monitoring began';
  materiality_eligible: false;
  executive_insight_eligible: false;
  provenance: 'DERIVED';
  provenance_label: string;
  effective_provenance: 'OBSERVED' | 'CERTIFIED_BOOTSTRAP';
  effective_provenance_label: string;
};
export type ActivationState = 'CURRENT_STATE' | 'OBSERVED_MOVEMENT' | 'COMPARABLE_WINDOW' | 'CERTIFIED_PERIOD_COMPARISON';
export type ReadinessMetric = { metric: string; completed: number; required: number; ready: boolean; state: string; activation_state: ActivationState | null; comparison_basis: string; available_days: number; required_days: number; suppression_code: string | null; suppression_reason: string | null; provenance: string; provenance_label: string; effective_provenance: string; effective_provenance_label: string };
export type InsightResponse = {
  formula_version: string;
  formula_versions?: string[];
  domains?: string[];
  horizon_days: number;
  cutoff: string;
  generated_at: string;
  summary: string;
  insights: InsightItem[];
  observed_movements: ObservedMovement[];
  suppressed: Array<{ key: string; code: string; message: string; activation_state?: ActivationState | null; comparison_basis?: string; provenance?: string; effective_provenance?: string; formula_version?: string; formula?: string; materiality_outcome?: string; coverage?: Record<string, unknown>; sources?: string[] }>;
  indicators: Array<{ key: string; metric: string; label: string; severity: 'informational'; value: number }>;
  readiness: { ready_metrics: number; total_metrics: number; required_snapshots: number; activation_counts: Record<string, number>; metrics: ReadinessMetric[] };
  scope?: { root_entity_id: number; entity_ids: number[]; recursive: boolean; group_id: number | null };
};
export type WidgetType = 'kpi' | 'line' | 'bar' | 'donut' | 'table' | 'detail_table' | 'matrix' | 'attention' | 'insight';
export type WidgetDefinition = { id: string; metric: string; type: WidgetType; title: string; palette: import('./palettes').WidgetPaletteKey; chartPalette: string; requiredColorSlots: number; w: number; h: number; x?: number; y?: number };
export type DashboardDefinition = { version: number; dateRangeDays: number; refreshMinutes: number; filters: { groupId: number | null }; widgets: WidgetDefinition[] };
export type SavedDashboard = { id: number | null; name: string; definition: DashboardDefinition; date_mod: string | null };
export type DashboardSummary = { id: number; name: string; is_active: boolean; date_mod: string | null };
export type DashboardTemplate = { key: string; name: string; description: string };
export type DashboardWorkspace = { dashboard: SavedDashboard; dashboards: DashboardSummary[]; templates: DashboardTemplate[] };
export type ReportSchedule = { id: number; name: string; dashboard_definitions_id: number; format: 'pdf' | 'csv'; frequency: 'daily' | 'weekly' | 'monthly'; send_hour: number; weekday: number | null; monthday: number | null; timezone: string; recipients: string[]; is_active: boolean; next_run_at: string; last_run_at: string | null };
