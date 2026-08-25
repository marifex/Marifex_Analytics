<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Insight;

final class InsightRuleRegistry
{
    public const PHASE_5A_FORMULA_VERSION = 'phase5a-1';
    public const PHASE_5B_FORMULA_VERSION = 'phase5b-1';
    public const FORMULA_VERSION = 'phase5a-1+phase5b-1';
    public const DENOMINATOR_MINIMUM = 5;
    public const RELATIVE_GATE = 10.0;

    /** @return array<string, string> */
    public static function formulas(): array
    {
        return [
            'net_ticket_flow' => 'created - resolved',
            'resolution_coverage' => 'resolved / created * 100',
            'backlog_growth_rate' => '(period end - period start) / period start * 100',
            'unassigned_rate' => 'numerator / denominator * 100',
            'high_priority_backlog_share' => 'priorities [4,5,6] / all priorities * 100',
            'top_group_workload_share' => 'largest dimension / all represented dimensions * 100',
            'open_request_source_concentration' => 'largest dimension / all represented dimensions * 100',
            'stale_inventory_exposure' => 'numerator / denominator * 100',
            'change_net_flow' => 'created - resolved',
            'change_resolution_coverage' => 'resolved / created * 100',
            'problem_net_flow' => 'created - resolved',
            'problem_resolution_coverage' => 'resolved / created * 100',
            'sla_breach_count_movement' => 'current cutoff value - previous cutoff value',
            'sla_breach_rate_movement' => 'current cutoff value - previous cutoff value',
            'approaching_sla_movement' => 'current cutoff value - previous cutoff value',
            'unsatisfied_response_movement' => 'sum(current period) compared with sum(previous equal period)',
            'repeat_incident_computer_movement' => 'count of non-zero certified dimensions',
            'licence_overallocation_movement' => 'current cutoff value - previous cutoff value',
            'licence_compliance_movement' => 'current cutoff value - previous cutoff value',
            'created_request_source_demand_movement' => 'sum created tickets by certified request-source dimension in equal periods',
            'ticket_reopen_count_movement' => 'sum(current period) compared with sum(previous equal period)',
            'ticket_reopen_rate_movement' => 'sum(numerator events) / sum(denominator events) * 100',
            'first_response_p90_movement' => 'current fixed-window value compared with previous fixed-window value',
            'first_response_p75_movement' => 'current fixed-window value compared with previous fixed-window value',
            'first_response_p50_movement' => 'current fixed-window value compared with previous fixed-window value',
            'customer_dissatisfaction_rate_movement' => 'certified fixed-window numerator / certified population * 100',
            'refused_solution_count_movement' => 'current fixed-window value compared with previous fixed-window value',
            'refused_solution_rate_movement' => 'certified fixed-window numerator / certified population * 100',
            'repeat_incident_asset_count_movement' => 'current fixed-window value compared with previous fixed-window value',
            'repeat_incident_asset_rate_movement' => 'certified fixed-window numerator / certified population * 100',
            'licence_utilization_movement' => 'certified fixed-window numerator / certified population * 100',
            'licence_coverage_gap_movement' => 'certified fixed-window numerator / certified population * 100',
        ];
    }

    /** @return list<string> */
    public static function formulaVersions(): array
    {
        return [self::PHASE_5A_FORMULA_VERSION, self::PHASE_5B_FORMULA_VERSION];
    }

    public static function formulaVersion(string $key): string
    {
        return in_array($key, [
            'created_request_source_demand_movement',
            'ticket_reopen_count_movement',
            'ticket_reopen_rate_movement',
            'first_response_p90_movement',
            'first_response_p75_movement',
            'first_response_p50_movement',
            'customer_dissatisfaction_rate_movement',
            'refused_solution_count_movement',
            'refused_solution_rate_movement',
            'repeat_incident_asset_count_movement',
            'repeat_incident_asset_rate_movement',
            'licence_utilization_movement',
            'licence_coverage_gap_movement',
        ], true) ? self::PHASE_5B_FORMULA_VERSION : self::PHASE_5A_FORMULA_VERSION;
    }

    /** @return array<string, list<string>> */
    public static function sources(): array
    {
        return [
            'net_ticket_flow' => ['created_vs_resolved_tickets'],
            'resolution_coverage' => ['created_vs_resolved_tickets'],
            'backlog_growth_rate' => ['historical_open_backlog'],
            'unassigned_rate' => ['unassigned_open_tickets', 'historical_open_backlog'],
            'high_priority_backlog_share' => ['open_tickets_by_priority'],
            'top_group_workload_share' => ['historical_group_backlog'],
            'open_request_source_concentration' => ['tickets_by_request_source'],
            'stale_inventory_exposure' => ['stale_computer_inventory', 'asset_inventory_total'],
            'change_net_flow' => ['daily_change_volume', 'daily_change_resolutions'],
            'change_resolution_coverage' => ['daily_change_volume', 'daily_change_resolutions'],
            'problem_net_flow' => ['daily_problem_volume', 'daily_problem_resolutions'],
            'problem_resolution_coverage' => ['daily_problem_volume', 'daily_problem_resolutions'],
            'sla_breach_count_movement' => ['sla_breach_count'],
            'sla_breach_rate_movement' => ['sla_breach_rate'],
            'approaching_sla_movement' => ['tickets_approaching_sla_breach'],
            'unsatisfied_response_movement' => ['unsatisfied_survey_responses'],
            'repeat_incident_computer_movement' => ['repeat_incident_computers'],
            'licence_overallocation_movement' => ['software_license_overallocated_seats'],
            'licence_compliance_movement' => ['software_license_compliance_rate'],
            'created_request_source_demand_movement' => ['created_tickets_by_request_source'],
            'ticket_reopen_count_movement' => ['ticket_reopen_events'],
            'ticket_reopen_rate_movement' => ['ticket_reopen_events', 'ticket_resolution_events'],
            'first_response_p90_movement' => ['first_response_p90_seconds'],
            'first_response_p75_movement' => ['first_response_p75_seconds'],
            'first_response_p50_movement' => ['first_response_p50_seconds'],
            'customer_dissatisfaction_rate_movement' => ['customer_dissatisfaction_rate', 'survey_responses_total'],
            'refused_solution_count_movement' => ['solution_refused_tickets'],
            'refused_solution_rate_movement' => ['refused_solution_rate', 'solution_proposed_tickets'],
            'repeat_incident_asset_count_movement' => ['repeat_incident_computers_90d'],
            'repeat_incident_asset_rate_movement' => ['repeat_incident_asset_rate', 'incident_linked_computers'],
            'licence_utilization_movement' => ['licence_utilization_rate', 'licence_covered_titles'],
            'licence_coverage_gap_movement' => ['licence_coverage_gap_rate', 'licence_installed_titles'],
        ];
    }

    public static function comparisonHorizon(string $key, int $selectedHorizon): int
    {
        return match ($key) {
            'stale_inventory_exposure', 'first_response_p90_movement', 'first_response_p75_movement',
            'first_response_p50_movement', 'customer_dissatisfaction_rate_movement',
            'refused_solution_count_movement', 'refused_solution_rate_movement' => 30,
            'repeat_incident_asset_count_movement', 'repeat_incident_asset_rate_movement' => 7,
            default => $selectedHorizon,
        };
    }

    /** @return array<string, array<string, mixed>> */
    public static function rules(): array
    {
        return [
            'net_ticket_flow' => self::rule('Net ticket flow', 'net_flow', 10.0, 'decrease', 'ticket', 20, 'tickets'),
            'resolution_coverage' => self::rule('Ticket resolution coverage', 'rate', 3.0, 'increase', 'ticket', 21, 'percent'),
            'backlog_growth_rate' => self::rule('Backlog growth rate', 'rate', 3.0, 'decrease', 'ticket', 22, 'percent'),
            'unassigned_rate' => self::rule('Unassigned backlog rate', 'rate', 3.0, 'decrease', 'ticket', 23, 'percent'),
            'high_priority_backlog_share' => self::rule('High-priority backlog share', 'composition', 5.0, 'decrease', 'ticket', 24, 'percent'),
            'top_group_workload_share' => self::rule('Top-group workload share', 'composition', 5.0, 'neutral', 'ticket', 25, 'percent'),
            'open_request_source_concentration' => self::rule('Open request-source concentration', 'composition', 5.0, 'neutral', 'ticket', 26, 'percent'),
            'stale_inventory_exposure' => self::rule('Stale-inventory exposure', 'rate', 3.0, 'decrease', 'asset', 40, 'percent'),
            'change_net_flow' => self::rule('Change net flow', 'net_flow', 10.0, 'decrease', 'change', 50, 'changes'),
            'change_resolution_coverage' => self::rule('Change resolution coverage', 'rate', 3.0, 'increase', 'change', 51, 'percent'),
            'problem_net_flow' => self::rule('Problem net flow', 'net_flow', 10.0, 'decrease', 'problem', 60, 'problems'),
            'problem_resolution_coverage' => self::rule('Problem resolution coverage', 'rate', 3.0, 'increase', 'problem', 61, 'percent'),
            'sla_breach_count_movement' => self::rule('Open SLA breaches', 'count', 5.0, 'decrease', 'ticket', 10, 'tickets'),
            'sla_breach_rate_movement' => self::rule('Open SLA breach rate', 'rate', 3.0, 'decrease', 'ticket', 11, 'percent'),
            'approaching_sla_movement' => self::rule('Tickets approaching SLA breach', 'count', 5.0, 'decrease', 'ticket', 12, 'tickets'),
            'unsatisfied_response_movement' => self::rule('Unsatisfied survey responses', 'count', 5.0, 'decrease', 'ticket', 30, 'responses'),
            'repeat_incident_computer_movement' => self::rule('Computers with repeated incidents', 'count', 5.0, 'decrease', 'asset', 41, 'computers'),
            'licence_overallocation_movement' => self::rule('Overallocated licence seats', 'count', 5.0, 'decrease', 'licence', 42, 'seats'),
            'licence_compliance_movement' => self::rule('Software licence compliance rate', 'rate', 3.0, 'increase', 'licence', 43, 'percent'),
            'created_request_source_demand_movement' => self::rule('Created-ticket demand', 'count', 5.0, 'neutral', 'ticket', 27, 'tickets'),
            'ticket_reopen_count_movement' => self::rule('Ticket reopen events', 'count', 5.0, 'decrease', 'ticket', 28, 'events'),
            'ticket_reopen_rate_movement' => self::rule('Ticket reopen event rate', 'rate', 3.0, 'decrease', 'ticket', 29, 'percent'),
            'first_response_p90_movement' => self::rule('First-response P90', 'duration', 900.0, 'decrease', 'ticket', 31, 'seconds'),
            'first_response_p75_movement' => self::rule('First-response P75', 'duration', 600.0, 'decrease', 'ticket', 32, 'seconds'),
            'first_response_p50_movement' => self::rule('First-response P50', 'duration', 300.0, 'decrease', 'ticket', 33, 'seconds'),
            'customer_dissatisfaction_rate_movement' => self::rule('Customer dissatisfaction rate', 'rate', 3.0, 'decrease', 'ticket', 34, 'percent'),
            'refused_solution_count_movement' => self::rule('Tickets with refused solutions', 'count', 5.0, 'decrease', 'ticket', 35, 'tickets'),
            'refused_solution_rate_movement' => self::rule('Refused-solution rate', 'rate', 3.0, 'decrease', 'ticket', 36, 'percent'),
            'repeat_incident_asset_count_movement' => self::rule('Repeat-incident computers (90 days)', 'count', 5.0, 'decrease', 'asset', 44, 'computers'),
            'repeat_incident_asset_rate_movement' => self::rule('Repeat-incident asset rate', 'rate', 3.0, 'decrease', 'asset', 45, 'percent'),
            'licence_utilization_movement' => self::rule('Licence utilization rate', 'rate', 3.0, 'neutral', 'licence', 46, 'percent'),
            'licence_coverage_gap_movement' => self::rule('Licence coverage-gap rate', 'rate', 3.0, 'decrease', 'licence', 47, 'percent'),
        ];
    }

    /** @return array<string, mixed> */
    private static function rule(string $label, string $class, float $absoluteGate, string $healthyDirection, string $evidence, int $rank, string $unit): array
    {
        return compact('label', 'class', 'absoluteGate', 'healthyDirection', 'evidence', 'rank', 'unit');
    }
}
