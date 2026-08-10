<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Insight;

final class InsightRuleRegistry
{
    public const FORMULA_VERSION = 'phase5a-1';
    public const DENOMINATOR_MINIMUM = 5;
    public const RELATIVE_GATE = 10.0;

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
        ];
    }

    /** @return array<string, mixed> */
    private static function rule(string $label, string $class, float $absoluteGate, string $healthyDirection, string $evidence, int $rank, string $unit): array
    {
        return compact('label', 'class', 'absoluteGate', 'healthyDirection', 'evidence', 'rank', 'unit');
    }
}
