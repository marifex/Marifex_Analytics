<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Insight;

use DateInterval;
use DateTimeImmutable;
use GlpiPlugin\Marifex\Analytics\ActivationDecision;
use GlpiPlugin\Marifex\Analytics\ActivationEvaluator;
use GlpiPlugin\Marifex\Analytics\ActivationState;
use GlpiPlugin\Marifex\Analytics\Provenance;
use GlpiPlugin\Marifex\Analytics\ProvenanceEvidence;
use LogicException;

final class InsightCalculator
{
    public function __construct(private readonly ActivationEvaluator $activation = new ActivationEvaluator())
    {
    }

    /**
     * @param array<string, array<string, mixed>> $datasets
     * @param array<string, array<string, mixed>> $sourceStates
     * @return array<string, mixed>
     */
    public function calculate(array $datasets, array $sourceStates, DateTimeImmutable $cutoff, int $horizon, ?int $groupId = null, array $domains = []): array
    {
        $horizon = in_array($horizon, [7, 30, 90, 180, 365], true) ? $horizon : 30;
        $domains = array_values(array_intersect(array_unique($domains), ['ticket', 'asset', 'licence', 'change', 'problem']));
        $suppressed = [];
        $candidates = [];
        $this->deriveFlow($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'created_vs_resolved_tickets', 'net_ticket_flow', 'resolution_coverage', 1, 2, 'No new tickets');
        $this->deriveBacklogGrowth($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, $groupId);
        $this->deriveRatio($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'unassigned_rate', 'unassigned_open_tickets', 'historical_open_backlog');
        $this->derivePriorityShare($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon);
        $this->deriveConcentration($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'top_group_workload_share', 'historical_group_backlog', true);
        $this->deriveConcentration($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'open_request_source_concentration', 'tickets_by_request_source', false);
        $this->deriveRatio($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 30, 'stale_inventory_exposure', 'stale_computer_inventory', 'asset_inventory_total');
        $this->deriveFlow($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'change_flow', 'change_net_flow', 'change_resolution_coverage', 1, 2, 'No new changes');
        $this->deriveFlow($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'problem_flow', 'problem_net_flow', 'problem_resolution_coverage', 1, 2, 'No new problems');

        $this->derivePointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'sla_breach_count_movement', 'sla_breach_count');
        $this->derivePointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'sla_breach_rate_movement', 'sla_breach_rate');
        $this->derivePointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'approaching_sla_movement', 'tickets_approaching_sla_breach');
        $this->derivePeriodScalarMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'unsatisfied_response_movement', 'unsatisfied_survey_responses');
        $this->deriveDimensionCountMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'repeat_incident_computer_movement', 'repeat_incident_computers');
        $this->derivePointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'licence_overallocation_movement', 'software_license_overallocated_seats');
        $this->derivePointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'licence_compliance_movement', 'software_license_compliance_rate');

        $this->deriveDimensionPeriodMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'created_request_source_demand_movement', 'created_tickets_by_request_source');
        $this->derivePeriodScalarMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'ticket_reopen_count_movement', 'ticket_reopen_events');
        $this->derivePeriodRate($candidates, $suppressed, $datasets, $sourceStates, $cutoff, $horizon, 'ticket_reopen_rate_movement', 'ticket_reopen_events', 'ticket_resolution_events', 5);
        $this->deriveFixedPointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'first_response_p90_movement', 'first_response_p90_seconds', 30, 20);
        $this->deriveFixedPointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'first_response_p75_movement', 'first_response_p75_seconds', 30, 20);
        $this->deriveFixedPointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'first_response_p50_movement', 'first_response_p50_seconds', 30, 20);
        $this->deriveFixedRatioMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'customer_dissatisfaction_rate_movement', 'customer_dissatisfaction_rate', 'survey_responses_total', 30, 30);
        $this->deriveFixedPointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'refused_solution_count_movement', 'solution_refused_tickets', 30, 0);
        $this->deriveFixedRatioMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'refused_solution_rate_movement', 'refused_solution_rate', 'solution_proposed_tickets', 30, 10);
        $this->deriveFixedPointMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'repeat_incident_asset_count_movement', 'repeat_incident_computers_90d', 7, 0);
        $this->deriveFixedRatioMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'repeat_incident_asset_rate_movement', 'repeat_incident_asset_rate', 'incident_linked_computers', 7, 5);
        $this->deriveFixedRatioMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'licence_utilization_movement', 'licence_utilization_rate', 'licence_covered_titles', $horizon, 50);
        $this->deriveFixedRatioMovement($candidates, $suppressed, $datasets, $sourceStates, $cutoff, 'licence_coverage_gap_movement', 'licence_coverage_gap_rate', 'licence_installed_titles', $horizon, 50);

        usort($candidates, static function (array $left, array $right): int {
            $directionRank = ['worsening' => 0, 'improving' => 1, 'neutral' => 2];
            return ($directionRank[$left['direction']] <=> $directionRank[$right['direction']])
                ?: ($right['materiality_score'] <=> $left['materiality_score'])
                ?: ($left['rank'] <=> $right['rank'])
                ?: strcmp($left['key'], $right['key']);
        });
        if ($domains !== []) {
            $candidates = array_values(array_filter($candidates, static fn(array $item): bool => in_array((string) ($item['evidence_target'] ?? ''), $domains, true)));
            $rules = InsightRuleRegistry::rules();
            $suppressed = array_filter($suppressed, static fn(array $item, string $key): bool => in_array((string) ($rules[$key]['evidence'] ?? ''), $domains, true), ARRAY_FILTER_USE_BOTH);
        }
        $insights = array_slice($candidates, 0, 5);

        $domainCore = [
            'ticket' => ['historical_open_backlog', 'created_vs_resolved_tickets', 'unassigned_open_tickets', 'tickets_approaching_sla_breach', 'sla_breach_count', 'sla_breach_rate', 'open_tickets_by_priority', 'historical_group_backlog', 'technician_workload_distribution', 'sla_breaches_by_technician'],
            'asset' => ['stale_computer_inventory', 'asset_inventory_total', 'repeat_incident_computers', 'incident_linked_computers', 'repeat_incident_computers_90d'],
            'licence' => ['software_license_overallocated_seats', 'software_license_compliance_rate', 'licence_covered_titles', 'licence_installed_titles', 'licence_utilization_rate', 'licence_coverage_gap_rate'],
            'change' => ['daily_change_volume', 'daily_change_resolutions'],
            'problem' => ['daily_problem_volume', 'daily_problem_resolutions'],
        ];
        $core = $domains === [] ? $domainCore['ticket'] : array_values(array_unique(array_merge(...array_map(static fn(string $domain): array => $domainCore[$domain], $domains))));
        $readiness = [];
        foreach ($core as $metric) {
            $dates = $datasets[$metric]['completed_dates'] ?? array_values(array_unique(array_map(static fn(array $point): string => (string) ($point['date'] ?? ''), $datasets[$metric]['series'] ?? [])));
            $state = (string) ($sourceStates[$metric]['state'] ?? 'missing');
            $baseline = is_array($datasets[$metric]['monitoring_baseline'] ?? null) ? $datasets[$metric]['monitoring_baseline'] : null;
            $baselineAt = isset($baseline['monitoring_baseline_at']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $baseline['monitoring_baseline_at']) : null;
            $decision = $this->activation->evaluate(
                $cutoff,
                $horizon,
                $dates,
                $state,
                $this->hasCurrentEvidence($datasets[$metric] ?? [], $cutoff),
                $this->sourceProvenance([$metric], $sourceStates),
                $baselineAt === false ? null : $baselineAt,
                is_array($baseline['evidence'] ?? null),
            );
            $readiness[] = [
                'metric' => $metric,
                'completed' => $decision->availableDays,
                'required' => $decision->requiredDays,
                'ready' => $decision->state === ActivationState::CERTIFIED_PERIOD_COMPARISON,
                'state' => $state,
            ] + $decision->toArray() + $this->sourceProvenance([$metric], $sourceStates)->toArray();
        }
        $readyCount = count(array_filter($readiness, static fn(array $item): bool => $item['ready']));
        $strongest = $insights[0]['narrative'] ?? null;
        $indicators = $this->informationalIndicators($datasets, $sourceStates, $cutoff, $groupId);
        $observedMovements = $this->observedMovements($readiness, $datasets, $sourceStates, $cutoff);
        $suppressed = $this->decorateSuppressions($suppressed, $datasets, $sourceStates, $cutoff, $horizon);

        return [
            'formula_version' => InsightRuleRegistry::FORMULA_VERSION,
            'formula_versions' => InsightRuleRegistry::formulaVersions(),
            'domains' => $domains === [] ? ['executive'] : $domains,
            'horizon_days' => $horizon,
            'cutoff' => $cutoff->format('Y-m-d'),
            'generated_at' => gmdate(DATE_ATOM),
            'summary' => $strongest ?? ($this->hasIncompleteHistory($readiness) ? sprintf('Building %d-day comparison baseline.', $horizon) : 'No material snapshot changes in the selected period.'),
            'insights' => $insights,
            'observed_movements' => $observedMovements,
            'suppressed' => array_values($suppressed),
            'indicators' => $indicators,
            'readiness' => [
                'ready_metrics' => $readyCount,
                'total_metrics' => count($readiness),
                'required_snapshots' => 2 * $horizon,
                'activation_counts' => array_count_values(array_map(static fn(array $item): string => (string) ($item['activation_state'] ?? 'UNAVAILABLE'), $readiness)),
                'metrics' => $readiness,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveFlow(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $datasetKey, string $netKey, string $coverageKey, int $createdId, int $resolvedId, string $zeroText): void
    {
        $sourceKeys = $datasetKey === 'change_flow' ? ['daily_change_volume', 'daily_change_resolutions'] : ($datasetKey === 'problem_flow' ? ['daily_problem_volume', 'daily_problem_resolutions'] : [$datasetKey]);
        if (!$this->sourcesCurrent($sourceKeys, $states, $suppressed, [$netKey, $coverageKey])) return;
        if (!$this->periodReady($sourceKeys, $datasets, $states, $cutoff, $horizon)) {
            $this->suppress($suppressed, $netKey, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon));
            $this->suppress($suppressed, $coverageKey, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon));
            return;
        }
        if (count($sourceKeys) === 1) {
            $currentCreated = $this->dimensionPeriodSum($datasets[$datasetKey], $createdId, $cutoff, $horizon, 0);
            $currentResolved = $this->dimensionPeriodSum($datasets[$datasetKey], $resolvedId, $cutoff, $horizon, 0);
            $previousCreated = $this->dimensionPeriodSum($datasets[$datasetKey], $createdId, $cutoff, $horizon, 1);
            $previousResolved = $this->dimensionPeriodSum($datasets[$datasetKey], $resolvedId, $cutoff, $horizon, 1);
        } else {
            [$createdMetric, $resolvedMetric] = $sourceKeys;
            $currentCreated = $this->scalarPeriodSum($datasets[$createdMetric], $cutoff, $horizon, 0);
            $currentResolved = $this->scalarPeriodSum($datasets[$resolvedMetric], $cutoff, $horizon, 0);
            $previousCreated = $this->scalarPeriodSum($datasets[$createdMetric], $cutoff, $horizon, 1);
            $previousResolved = $this->scalarPeriodSum($datasets[$resolvedMetric], $cutoff, $horizon, 1);
        }
        if ($currentCreated === 0.0 && $currentResolved === 0.0 && $previousCreated === 0.0 && $previousResolved === 0.0) {
            $this->suppress($suppressed, $netKey, 'NO_ACTIVITY', 'No activity in either comparison period.');
        } else {
            $this->candidate($candidates, $suppressed, $netKey, $currentCreated - $currentResolved, $previousCreated - $previousResolved, [
                'formula' => 'created - resolved', 'current_numerator' => $currentCreated, 'current_denominator' => $currentResolved,
                'previous_numerator' => $previousCreated, 'previous_denominator' => $previousResolved,
            ], $horizon, $states, $sourceKeys);
        }
        if ($currentCreated < InsightRuleRegistry::DENOMINATOR_MINIMUM || $previousCreated < InsightRuleRegistry::DENOMINATOR_MINIMUM) {
            $message = ($currentCreated === 0.0 || $previousCreated === 0.0) ? $zeroText : sprintf('Insufficient data: %.0f of %d required.', min($currentCreated, $previousCreated), InsightRuleRegistry::DENOMINATOR_MINIMUM);
            $this->suppress($suppressed, $coverageKey, $currentCreated === 0.0 || $previousCreated === 0.0 ? 'NO_ACTIVITY' : 'DENOMINATOR_BELOW_MINIMUM', $message);
        } else {
            $this->candidate($candidates, $suppressed, $coverageKey, $currentResolved / $currentCreated * 100, $previousResolved / $previousCreated * 100, [
                'formula' => 'resolved / created * 100', 'current_numerator' => $currentResolved, 'current_denominator' => $currentCreated,
                'previous_numerator' => $previousResolved, 'previous_denominator' => $previousCreated,
            ], $horizon, $states, $sourceKeys);
        }
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveBacklogGrowth(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, ?int $groupId): void
    {
        $key = 'backlog_growth_rate'; $metric = 'historical_open_backlog';
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $current = $this->scalarAt($datasets[$metric] ?? [], $cutoff);
        $start = $this->scalarAt($datasets[$metric] ?? [], $cutoff->sub(new DateInterval('P' . $horizon . 'D')));
        $previousStart = $this->scalarAt($datasets[$metric] ?? [], $cutoff->sub(new DateInterval('P' . (2 * $horizon) . 'D')));
        if ($current === null || $start === null || $previousStart === null) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', 'Required backlog boundary snapshots are unavailable.'); return; }
        if ($start < InsightRuleRegistry::DENOMINATOR_MINIMUM || $previousStart < InsightRuleRegistry::DENOMINATOR_MINIMUM) { $this->suppress($suppressed, $key, 'DENOMINATOR_BELOW_MINIMUM', sprintf('Insufficient data: %.0f of %d required.', min($start, $previousStart), InsightRuleRegistry::DENOMINATOR_MINIMUM)); return; }
        $this->candidate($candidates, $suppressed, $key, ($current - $start) / $start * 100, ($start - $previousStart) / $previousStart * 100, [
            'formula' => '(period end - period start) / period start * 100', 'current_numerator' => $current - $start, 'current_denominator' => $start,
            'previous_numerator' => $start - $previousStart, 'previous_denominator' => $previousStart, 'group_id' => $groupId,
        ], $horizon, $states, [$metric]);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveRatio(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $key, string $numeratorMetric, string $denominatorMetric): void
    {
        if (!$this->sourcesCurrent([$numeratorMetric, $denominatorMetric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$numeratorMetric, $denominatorMetric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $previousCutoff = $cutoff->sub(new DateInterval('P' . $horizon . 'D'));
        $cn = $this->scalarAt($datasets[$numeratorMetric] ?? [], $cutoff); $cd = $this->scalarAt($datasets[$denominatorMetric] ?? [], $cutoff);
        $pn = $this->scalarAt($datasets[$numeratorMetric] ?? [], $previousCutoff); $pd = $this->scalarAt($datasets[$denominatorMetric] ?? [], $previousCutoff);
        if ($cn === null || $cd === null || $pn === null || $pd === null) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', 'Required cutoff snapshots are unavailable.'); return; }
        if ($cd < InsightRuleRegistry::DENOMINATOR_MINIMUM || $pd < InsightRuleRegistry::DENOMINATOR_MINIMUM) { $this->suppress($suppressed, $key, 'DENOMINATOR_BELOW_MINIMUM', sprintf('Insufficient data: %.0f of %d required.', min($cd, $pd), InsightRuleRegistry::DENOMINATOR_MINIMUM)); return; }
        $this->candidate($candidates, $suppressed, $key, $cn / $cd * 100, $pn / $pd * 100, [
            'formula' => 'numerator / denominator * 100', 'current_numerator' => $cn, 'current_denominator' => $cd,
            'previous_numerator' => $pn, 'previous_denominator' => $pd,
        ], $horizon, $states, [$numeratorMetric, $denominatorMetric]);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function derivePriorityShare(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon): void
    {
        $key = 'high_priority_backlog_share'; $metric = 'open_tickets_by_priority';
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $previous = $cutoff->sub(new DateInterval('P' . $horizon . 'D'));
        $currentMap = $this->dimensionsAt($datasets[$metric] ?? [], $cutoff); $previousMap = $this->dimensionsAt($datasets[$metric] ?? [], $previous);
        $cd = array_sum(array_column($currentMap, 'value')); $pd = array_sum(array_column($previousMap, 'value'));
        if ($cd < 5 || $pd < 5) { $this->suppress($suppressed, $key, 'DENOMINATOR_BELOW_MINIMUM', sprintf('Insufficient data: %.0f of 5 required.', min($cd, $pd))); return; }
        $cn = array_sum(array_map(static fn(array $item): float => in_array($item['id'], [4, 5, 6], true) ? $item['value'] : 0.0, $currentMap));
        $pn = array_sum(array_map(static fn(array $item): float => in_array($item['id'], [4, 5, 6], true) ? $item['value'] : 0.0, $previousMap));
        $this->candidate($candidates, $suppressed, $key, $cn / $cd * 100, $pn / $pd * 100, ['formula' => 'priorities [4,5,6] / all priorities * 100', 'current_numerator' => $cn, 'current_denominator' => $cd, 'previous_numerator' => $pn, 'previous_denominator' => $pd], $horizon, $states, [$metric], $this->contributor($currentMap, $previousMap));
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveConcentration(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $key, string $metric, bool $majorityRule): void
    {
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $previous = $cutoff->sub(new DateInterval('P' . $horizon . 'D'));
        $currentMap = $this->dimensionsAt($datasets[$metric] ?? [], $cutoff, $metric === 'historical_group_backlog');
        $previousMap = $this->dimensionsAt($datasets[$metric] ?? [], $previous, $metric === 'historical_group_backlog');
        $cd = array_sum(array_column($currentMap, 'value')); $pd = array_sum(array_column($previousMap, 'value'));
        if ($cd < 5 || $pd < 5) { $this->suppress($suppressed, $key, 'DENOMINATOR_BELOW_MINIMUM', sprintf('Insufficient data: %.0f of 5 required.', min($cd, $pd))); return; }
        $cn = max(array_column($currentMap, 'value') ?: [0]); $pn = max(array_column($previousMap, 'value') ?: [0]);
        $extra = [];
        if ($majorityRule && count(array_filter($currentMap, static fn(array $item): bool => $item['value'] > 0)) >= 3 && ($cn / $cd * 100) > 50.0) $extra['indicator'] = 'Majority concentration';
        $this->candidate($candidates, $suppressed, $key, $cn / $cd * 100, $pn / $pd * 100, ['formula' => 'largest dimension / all represented dimensions * 100', 'current_numerator' => $cn, 'current_denominator' => $cd, 'previous_numerator' => $pn, 'previous_denominator' => $pd] + $extra, $horizon, $states, [$metric], $this->contributor($currentMap, $previousMap));
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function derivePointMovement(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $key, string $metric): void
    {
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $current = $this->scalarAt($datasets[$metric] ?? [], $cutoff); $previous = $this->scalarAt($datasets[$metric] ?? [], $cutoff->sub(new DateInterval('P' . $horizon . 'D')));
        if ($current === null || $previous === null) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', 'Required cutoff snapshots are unavailable.'); return; }
        $this->candidate($candidates, $suppressed, $key, $current, $previous, ['formula' => 'current cutoff value - previous cutoff value', 'current_numerator' => $current, 'previous_numerator' => $previous], $horizon, $states, [$metric]);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveDimensionCountMovement(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $key, string $metric): void
    {
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $currentMap = $this->dimensionsAt($datasets[$metric] ?? [], $cutoff); $previousMap = $this->dimensionsAt($datasets[$metric] ?? [], $cutoff->sub(new DateInterval('P' . $horizon . 'D')));
        if ($currentMap === [] && $previousMap === []) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', 'Required cutoff snapshots are unavailable.'); return; }
        $this->candidate($candidates, $suppressed, $key, (float) count(array_filter($currentMap, static fn(array $item): bool => $item['value'] > 0)), (float) count(array_filter($previousMap, static fn(array $item): bool => $item['value'] > 0)), ['formula' => 'count of non-zero certified dimensions'], $horizon, $states, [$metric], $this->contributor($currentMap, $previousMap));
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function derivePeriodScalarMovement(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $key, string $metric): void
    {
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $current = $this->scalarPeriodSum($datasets[$metric] ?? [], $cutoff, $horizon, 0);
        $previous = $this->scalarPeriodSum($datasets[$metric] ?? [], $cutoff, $horizon, 1);
        $this->candidate($candidates, $suppressed, $key, $current, $previous, ['formula' => 'sum(current period) compared with sum(previous equal period)', 'current_numerator' => $current, 'previous_numerator' => $previous], $horizon, $states, [$metric]);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveDimensionPeriodMovement(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $key, string $metric): void
    {
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $current = $this->dimensionPeriodMap($datasets[$metric] ?? [], $cutoff, $horizon, 0);
        $previous = $this->dimensionPeriodMap($datasets[$metric] ?? [], $cutoff, $horizon, 1);
        $contributors = $this->contributors($current, $previous, 3);
        $this->candidate($candidates, $suppressed, $key, array_sum(array_column($current, 'value')), array_sum(array_column($previous, 'value')), [
            'formula' => 'sum created tickets by certified request-source dimension in equal periods',
            'contributors' => $contributors,
        ], $horizon, $states, [$metric], $contributors[0] ?? null);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function derivePeriodRate(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon, string $key, string $numerator, string $denominator, int $minimum): void
    {
        if (!$this->sourcesCurrent([$numerator, $denominator], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$numerator, $denominator], $datasets, $states, $cutoff, $horizon)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building %d-day comparison baseline.', $horizon)); return; }
        $cn = $this->scalarPeriodSum($datasets[$numerator] ?? [], $cutoff, $horizon, 0); $pn = $this->scalarPeriodSum($datasets[$numerator] ?? [], $cutoff, $horizon, 1);
        $cd = $this->scalarPeriodSum($datasets[$denominator] ?? [], $cutoff, $horizon, 0); $pd = $this->scalarPeriodSum($datasets[$denominator] ?? [], $cutoff, $horizon, 1);
        if ($cd < $minimum || $pd < $minimum) { $this->suppress($suppressed, $key, 'DENOMINATOR_BELOW_MINIMUM', sprintf('Insufficient data: %.0f of %d required.', min($cd, $pd), $minimum)); return; }
        $this->candidate($candidates, $suppressed, $key, $cn / $cd * 100, $pn / $pd * 100, [
            'formula' => 'sum(numerator events) / sum(denominator events) * 100', 'current_numerator' => $cn, 'current_denominator' => $cd, 'previous_numerator' => $pn, 'previous_denominator' => $pd,
        ], $horizon, $states, [$numerator, $denominator]);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveFixedPointMovement(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, string $key, string $metric, int $offsetDays, int $minimumSamples): void
    {
        if (!$this->sourcesCurrent([$metric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$metric], $datasets, $states, $cutoff, $offsetDays)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building fixed %d-day comparison baseline.', $offsetDays)); return; }
        $currentPoint = $this->pointAt($datasets[$metric] ?? [], $cutoff); $previousPoint = $this->pointAt($datasets[$metric] ?? [], $cutoff->sub(new DateInterval('P' . $offsetDays . 'D')));
        if ($currentPoint === null || $previousPoint === null) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building fixed %d-day comparison baseline.', $offsetDays)); return; }
        $samples = min((int) ($currentPoint['sample_count'] ?? PHP_INT_MAX), (int) ($previousPoint['sample_count'] ?? PHP_INT_MAX));
        if ($minimumSamples > 0 && $samples < $minimumSamples) { $this->suppress($suppressed, $key, 'DENOMINATOR_BELOW_MINIMUM', sprintf('Insufficient data: %d of %d observations required.', $samples, $minimumSamples)); return; }
        $this->candidate($candidates, $suppressed, $key, (float) $currentPoint['value'], (float) $previousPoint['value'], [
            'formula' => 'current fixed-window value compared with previous fixed-window value', 'current_numerator' => (float) $currentPoint['value'], 'previous_numerator' => (float) $previousPoint['value'], 'current_sample_count' => $currentPoint['sample_count'] ?? null, 'previous_sample_count' => $previousPoint['sample_count'] ?? null,
        ], $offsetDays, $states, [$metric]);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function deriveFixedRatioMovement(array &$candidates, array &$suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, string $key, string $rateMetric, string $populationMetric, int $offsetDays, int $minimum): void
    {
        if (!$this->sourcesCurrent([$rateMetric, $populationMetric], $states, $suppressed, [$key])) return;
        if (!$this->periodReady([$rateMetric, $populationMetric], $datasets, $states, $cutoff, $offsetDays)) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building fixed %d-day comparison baseline.', $offsetDays)); return; }
        $current = $this->pointAt($datasets[$rateMetric] ?? [], $cutoff); $previous = $this->pointAt($datasets[$rateMetric] ?? [], $cutoff->sub(new DateInterval('P' . $offsetDays . 'D')));
        $currentPopulation = $this->scalarAt($datasets[$populationMetric] ?? [], $cutoff); $previousPopulation = $this->scalarAt($datasets[$populationMetric] ?? [], $cutoff->sub(new DateInterval('P' . $offsetDays . 'D')));
        if ($current === null || $previous === null || $currentPopulation === null || $previousPopulation === null) { $this->suppress($suppressed, $key, 'INSUFFICIENT_HISTORY', sprintf('Building fixed %d-day comparison baseline.', $offsetDays)); return; }
        if ($currentPopulation < $minimum || $previousPopulation < $minimum) { $this->suppress($suppressed, $key, 'DENOMINATOR_BELOW_MINIMUM', sprintf('Insufficient data: %.0f of %d required.', min($currentPopulation, $previousPopulation), $minimum)); return; }
        $this->candidate($candidates, $suppressed, $key, (float) $current['value'], (float) $previous['value'], [
            'formula' => 'certified fixed-window numerator / certified population * 100', 'current_denominator' => $currentPopulation, 'previous_denominator' => $previousPopulation,
        ], $offsetDays, $states, [$rateMetric, $populationMetric]);
    }

    /** @param list<array<string, mixed>> $candidates @param array<string, array<string, mixed>> $suppressed @param array<string, mixed> $inputs @param array<string, array<string, mixed>> $states @param list<string> $sourceKeys @param array<string, mixed>|null $contributor */
    private function candidate(array &$candidates, array &$suppressed, string $key, float $current, float $previous, array $inputs, int $horizon, array $states, array $sourceKeys, ?array $contributor = null): void
    {
        $rule = InsightRuleRegistry::rules()[$key];
        $registeredFormula = InsightRuleRegistry::formulas()[$key] ?? throw new LogicException(sprintf('No certified formula is registered for %s.', $key));
        if (($inputs['formula'] ?? null) !== $registeredFormula) {
            throw new LogicException(sprintf('Calculation formula for %s diverges from the certified registry.', $key));
        }
        $inputs['formula'] = $registeredFormula;
        $provenance = ProvenanceEvidence::derived(...array_map(fn(string $source): ProvenanceEvidence => $this->sourceProvenance([$source], $states), $sourceKeys));
        $change = $current - $previous;
        $relative = $previous == 0.0 ? null : ($change / abs($previous)) * 100;
        $passes = $previous == 0.0
            ? abs($change) >= $rule['absoluteGate']
            : abs($change) >= $rule['absoluteGate'] && abs((float) $relative) >= InsightRuleRegistry::RELATIVE_GATE;
        if (!$passes) {
            $this->suppress($suppressed, $key, 'NO_MATERIAL_CHANGE', 'Valid movement did not pass both materiality gates.', [
                'current' => $current, 'previous' => $previous, 'absolute_change' => $change,
                'relative_change_percent' => $relative, 'inputs' => $inputs,
                'absolute_gate' => $rule['absoluteGate'], 'relative_gate_percent' => InsightRuleRegistry::RELATIVE_GATE,
                'materiality_outcome' => 'failed',
            ]);
            return;
        }
        $score = $previous == 0.0 ? abs($change) / $rule['absoluteGate'] : min(abs($change) / $rule['absoluteGate'], abs((float) $relative) / 10.0);
        $direction = $this->direction((string) $rule['healthyDirection'], $change);
        $freshnessTimes = array_values(array_filter(array_map(static fn(string $source): ?string => isset($states[$source]['completed_at']) ? (string) $states[$source]['completed_at'] : null, $sourceKeys)));
        sort($freshnessTimes);
        $unit = (string) $rule['unit'];
        $movementText = $previous == 0.0 ? 'New from zero' : ($current == 0.0 ? 'Cleared to zero' : $this->formatChange($change, $unit));
        $relativeText = $relative === null ? '' : sprintf(' (%s%.1f%%)', $relative >= 0 ? '+' : '', $relative);
        $narrative = sprintf('%s %s to %s; %s%s versus previous %d days.', $rule['label'], $change >= 0 ? 'increased' : 'decreased', $this->formatValue($current, $unit), $movementText, $relativeText, $horizon);
        if ($contributor !== null && $change != 0.0) $narrative .= sprintf(' Largest contributing dimension change: %s %s.', $contributor['label'], $this->formatSigned($contributor['delta'], $unit === 'percent' ? 'records' : $unit));
        $candidates[] = [
            'key' => $key, 'label' => $rule['label'], 'direction' => $direction, 'rank' => $rule['rank'], 'unit' => $unit,
            'current' => round($current, $unit === 'percent' ? 1 : 0), 'previous' => round($previous, $unit === 'percent' ? 1 : 0),
            'absolute_change' => round($change, $unit === 'percent' ? 1 : 0), 'relative_change_percent' => $relative === null ? null : round($relative, 1),
            'percentage_point_change' => $unit === 'percent' ? round($change, 1) : null, 'materiality_score' => round($score, 4),
            'comparison_text' => sprintf('versus previous %d days', $horizon), 'narrative' => $narrative,
            'contributor' => $contributor, 'evidence_target' => $rule['evidence'], 'source' => 'data_mart',
            'activation_state' => ActivationState::CERTIFIED_PERIOD_COMPARISON->value,
            'comparison_basis' => ActivationState::CERTIFIED_PERIOD_COMPARISON->comparisonBasis($horizon),
            'comparison_horizon_days' => $horizon,
            'as_of' => $freshnessTimes[0] ?? null,
            'calculation' => $inputs + ['formula_version' => InsightRuleRegistry::formulaVersion($key), 'absolute_gate' => $rule['absoluteGate'], 'relative_gate_percent' => 10.0, 'materiality_outcome' => 'passed', 'result' => $current],
        ] + $provenance->toArray();
    }

    /** @param list<string> $sources @param array<string, array<string, mixed>> $states @param array<string, array<string, mixed>> $suppressed @param list<string> $keys */
    private function sourcesCurrent(array $sources, array $states, array &$suppressed, array $keys): bool
    {
        foreach ($sources as $source) {
            $state = (string) ($states[$source]['state'] ?? 'missing');
            if (!$this->sourceProvenance([$source], $states)->isEligibleForCertifiedUse()) {
                foreach ($keys as $key) $this->suppress($suppressed, $key, 'UNAVAILABLE_SOURCE', sprintf('%s evidence is not eligible for certified analytical use.', $source));
                return false;
            }
            if ($state !== 'current') {
                $code = match ($state) { 'stale' => 'STALE_SOURCE', 'unavailable' => 'UNAVAILABLE_SOURCE', default => 'MISSING_SOURCE' };
                $message = (string) ($states[$source]['reason'] ?? sprintf('%s source is %s.', $source, $state));
                foreach ($keys as $key) $this->suppress($suppressed, $key, $code, $message);
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $sources @param array<string, array<string, mixed>> $states */
    private function sourceProvenance(array $sources, array $states): ProvenanceEvidence
    {
        $evidence = [];
        foreach ($sources as $source) {
            $code = (string) ($states[$source]['effective_provenance'] ?? $states[$source]['provenance'] ?? Provenance::OBSERVED->value);
            $provenance = Provenance::tryFrom($code) ?? Provenance::UNCERTIFIED_RECONSTRUCTION;
            $evidence[] = match ($provenance) {
                Provenance::OBSERVED => ProvenanceEvidence::observed(),
                Provenance::CERTIFIED_BOOTSTRAP => ProvenanceEvidence::certifiedBootstrap(),
                Provenance::UNCERTIFIED_RECONSTRUCTION => ProvenanceEvidence::uncertifiedReconstruction(),
                Provenance::DERIVED => ProvenanceEvidence::uncertifiedReconstruction(),
            };
        }
        if (count($evidence) === 1) {
            return $evidence[0];
        }
        if (array_filter($evidence, static fn(ProvenanceEvidence $item): bool => !$item->isEligibleForCertifiedUse()) !== []) {
            return ProvenanceEvidence::uncertifiedReconstruction();
        }
        return ProvenanceEvidence::derived(...$evidence);
    }

    /** @param array<string, mixed> $dataset */
    private function hasCurrentEvidence(array $dataset, DateTimeImmutable $cutoff): bool
    {
        // A completed observation certifies a valid zero even when no rollup row exists.
        if (($dataset['current_observation_complete'] ?? false) === true) {
            return true;
        }
        if (array_key_exists('value', $dataset)) {
            return true;
        }
        foreach ($dataset['series'] ?? [] as $point) {
            if (($point['date'] ?? null) === $cutoff->format('Y-m-d')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Observational movements are deliberately emitted outside the materiality candidate pipeline.
     * They are factual absolute deltas only and can never be ranked into the Executive brief.
     *
     * @param list<array<string, mixed>> $readiness
     * @param array<string, array<string, mixed>> $datasets
     * @param array<string, array<string, mixed>> $states
     * @return list<array<string, mixed>>
     */
    private function observedMovements(array $readiness, array $datasets, array $states, DateTimeImmutable $cutoff): array
    {
        $items = [];
        foreach ($readiness as $status) {
            if (($status['activation_state'] ?? null) !== ActivationState::OBSERVED_MOVEMENT->value) {
                continue;
            }
            $metric = (string) $status['metric'];
            $baseline = $datasets[$metric]['monitoring_baseline'] ?? null;
            $baselineValue = is_array($baseline) && is_array($baseline['evidence'] ?? null) && isset($baseline['evidence']['value'])
                ? (float) $baseline['evidence']['value']
                : null;
            $currentValue = $this->scalarAt($datasets[$metric] ?? [], $cutoff);
            if ($baselineValue === null || $currentValue === null) {
                continue;
            }
            $provenance = ProvenanceEvidence::derived(
                $this->sourceProvenance([$metric], $states),
                ProvenanceEvidence::observed(),
            );
            $items[] = [
                'metric' => $metric,
                'label' => (string) ($datasets[$metric]['label'] ?? $metric),
                'current' => $currentValue,
                'baseline' => $baselineValue,
                'absolute_change' => $currentValue - $baselineValue,
                'monitoring_baseline_at' => (string) $baseline['monitoring_baseline_at'],
                'activation_state' => ActivationState::OBSERVED_MOVEMENT->value,
                'comparison_basis' => ActivationState::OBSERVED_MOVEMENT->comparisonBasis(0),
                'materiality_eligible' => false,
                'executive_insight_eligible' => false,
                'formula_version' => InsightRuleRegistry::PHASE_5A_FORMULA_VERSION,
                'formula' => 'latest certified observation - stable monitoring baseline',
            ] + $provenance->toArray();
        }
        return $items;
    }

    /** @param list<string> $metrics @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states */
    private function periodReady(array $metrics, array $datasets, array $states, DateTimeImmutable $cutoff, int $horizon): bool
    {
        foreach ($metrics as $metric) {
            $dates = $datasets[$metric]['completed_dates'] ?? array_values(array_unique(array_map(static fn(array $point): string => (string) ($point['date'] ?? ''), $datasets[$metric]['series'] ?? [])));
            $baseline = is_array($datasets[$metric]['monitoring_baseline'] ?? null) ? $datasets[$metric]['monitoring_baseline'] : null;
            $baselineAt = isset($baseline['monitoring_baseline_at']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $baseline['monitoring_baseline_at']) : null;
            $decision = $this->activation->evaluate(
                $cutoff,
                $horizon,
                $dates,
                (string) ($states[$metric]['state'] ?? 'missing'),
                $this->hasCurrentEvidence($datasets[$metric] ?? [], $cutoff),
                $this->sourceProvenance([$metric], $states),
                $baselineAt === false ? null : $baselineAt,
                is_array($baseline['evidence'] ?? null),
            );
            if ($decision->state !== ActivationState::CERTIFIED_PERIOD_COMPARISON) return false;
        }
        return true;
    }

    /** @return list<string> */
    private function dateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dates = [];
        for ($date = $from; $date <= $to; $date = $date->add(new DateInterval('P1D'))) $dates[] = $date->format('Y-m-d');
        return $dates;
    }

    /** @param array<string, mixed> $dataset */
    private function scalarAt(array $dataset, DateTimeImmutable $date): ?float
    {
        foreach ($dataset['series'] ?? [] as $point) if (($point['date'] ?? null) === $date->format('Y-m-d')) return (float) $point['value'];
        return null;
    }

    /** @param array<string, mixed> $dataset @return array<string, mixed>|null */
    private function pointAt(array $dataset, DateTimeImmutable $date): ?array
    {
        foreach ($dataset['series'] ?? [] as $point) if (($point['date'] ?? null) === $date->format('Y-m-d')) return $point;
        return null;
    }

    /** @param array<string, mixed> $dataset */
    private function scalarPeriodSum(array $dataset, DateTimeImmutable $cutoff, int $horizon, int $offset): float
    {
        [$from, $to] = $this->period($cutoff, $horizon, $offset);
        return array_sum(array_map(static fn(array $point): float => ($point['date'] >= $from && $point['date'] <= $to) ? (float) $point['value'] : 0.0, $dataset['series'] ?? []));
    }

    /** @param array<string, mixed> $dataset */
    private function dimensionPeriodSum(array $dataset, int $dimensionId, DateTimeImmutable $cutoff, int $horizon, int $offset): float
    {
        [$from, $to] = $this->period($cutoff, $horizon, $offset);
        return array_sum(array_map(static fn(array $point): float => ($point['date'] >= $from && $point['date'] <= $to && (int) ($point['dimension_id'] ?? -1) === $dimensionId) ? (float) $point['value'] : 0.0, $dataset['series'] ?? []));
    }

    /** @param array<string, mixed> $dataset @return list<array{id:int,label:string,value:float}> */
    private function dimensionPeriodMap(array $dataset, DateTimeImmutable $cutoff, int $horizon, int $offset): array
    {
        [$from, $to] = $this->period($cutoff, $horizon, $offset);
        $items = [];
        foreach ($dataset['series'] ?? [] as $point) {
            if (($point['date'] ?? '') < $from || ($point['date'] ?? '') > $to) continue;
            $id = (int) ($point['dimension_id'] ?? 0);
            $items[$id] ??= ['id' => $id, 'label' => (string) ($point['dimension'] ?? ('Value #' . $id)), 'value' => 0.0];
            $items[$id]['value'] += (float) $point['value'];
        }
        ksort($items, SORT_NUMERIC);
        return array_values($items);
    }

    /** @return array{0:string,1:string} */
    private function period(DateTimeImmutable $cutoff, int $horizon, int $offset): array
    {
        $to = $cutoff->sub(new DateInterval('P' . ($offset * $horizon) . 'D'));
        $from = $to->sub(new DateInterval('P' . ($horizon - 1) . 'D'));
        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /** @param array<string, mixed> $dataset @return list<array{id:int,label:string,value:float}> */
    private function dimensionsAt(array $dataset, DateTimeImmutable $date, bool $excludeUnassigned = false): array
    {
        $items = [];
        foreach ($dataset['series'] ?? [] as $point) {
            $id = (int) ($point['dimension_id'] ?? 0);
            if (($point['date'] ?? null) !== $date->format('Y-m-d') || ($excludeUnassigned && $id === 0)) continue;
            $items[] = ['id' => $id, 'label' => (string) ($point['dimension'] ?? ('Value #' . $id)), 'value' => (float) $point['value']];
        }
        return $items;
    }

    /** @param list<array{id:int,label:string,value:float}> $current @param list<array{id:int,label:string,value:float}> $previous @return array<string, mixed>|null */
    private function contributor(array $current, array $previous): ?array
    {
        $union = [];
        foreach (array_merge($current, $previous) as $item) $union[$item['id']] = $item['label'];
        if ($union === []) return null;
        ksort($union, SORT_NUMERIC);
        $currentValues = array_column($current, 'value', 'id'); $previousValues = array_column($previous, 'value', 'id');
        $selected = null;
        foreach ($union as $id => $label) {
            $delta = (float) ($currentValues[$id] ?? 0) - (float) ($previousValues[$id] ?? 0);
            if ($selected === null || abs($delta) > abs($selected['delta'])) $selected = ['dimension_id' => $id, 'label' => $label, 'delta' => $delta];
        }
        return $selected !== null && $selected['delta'] != 0.0 ? $selected : null;
    }

    /** @param list<array{id:int,label:string,value:float}> $current @param list<array{id:int,label:string,value:float}> $previous @return list<array<string, mixed>> */
    private function contributors(array $current, array $previous, int $limit): array
    {
        $union = [];
        foreach (array_merge($current, $previous) as $item) $union[$item['id']] = $item['label'];
        $currentValues = array_column($current, 'value', 'id'); $previousValues = array_column($previous, 'value', 'id');
        $items = [];
        foreach ($union as $id => $label) {
            $delta = (float) ($currentValues[$id] ?? 0) - (float) ($previousValues[$id] ?? 0);
            if ($delta != 0.0) $items[] = ['dimension_id' => (int) $id, 'label' => $label, 'delta' => $delta];
        }
        usort($items, static fn(array $left, array $right): int => abs($right['delta']) <=> abs($left['delta']) ?: $left['dimension_id'] <=> $right['dimension_id']);
        return array_slice($items, 0, $limit);
    }

    private function direction(string $healthyDirection, float $change): string
    {
        if ($healthyDirection === 'neutral' || $change == 0.0) return 'neutral';
        $improving = ($healthyDirection === 'increase' && $change > 0) || ($healthyDirection === 'decrease' && $change < 0);
        return $improving ? 'improving' : 'worsening';
    }

    private function formatValue(float $value, string $unit): string
    {
        if ($unit === 'percent') return number_format($value, 1) . '%';
        if ($unit === 'seconds') return $this->formatDuration($value);
        return number_format($value, 0) . ' ' . $unit;
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) return number_format($seconds, 0) . 's';
        if ($seconds < 3600) return number_format($seconds / 60, 1) . 'm';
        return number_format($seconds / 3600, 1) . 'h';
    }

    private function formatSigned(float $value, string $unit): string
    {
        return ($value >= 0 ? '+' : '') . $this->formatValue($value, $unit);
    }

    private function formatChange(float $value, string $unit): string
    {
        return $unit === 'percent'
            ? ($value >= 0 ? '+' : '') . number_format($value, 1) . ' percentage points'
            : $this->formatSigned($value, $unit);
    }

    /** @param array<string, array<string, mixed>> $datasets @param array<string, array<string, mixed>> $states @return list<array<string, mixed>> */
    private function informationalIndicators(array $datasets, array $states, DateTimeImmutable $cutoff, ?int $groupId): array
    {
        if ($groupId !== null || ($states['historical_group_backlog']['state'] ?? 'missing') !== 'current') return [];
        $groups = $this->dimensionsAt($datasets['historical_group_backlog'] ?? [], $cutoff, true);
        $nonzero = array_values(array_filter($groups, static fn(array $item): bool => $item['value'] > 0));
        $total = array_sum(array_column($nonzero, 'value'));
        $largest = max(array_column($nonzero, 'value') ?: [0]);
        if (count($nonzero) < 3 || $total < InsightRuleRegistry::DENOMINATOR_MINIMUM || ($largest / $total * 100) <= 50.0) return [];
        return [['key' => 'majority_concentration', 'metric' => 'historical_group_backlog', 'label' => 'Majority concentration', 'severity' => 'informational', 'value' => round($largest / $total * 100, 1)]];
    }

    /** @param array<string, array<string, mixed>> $suppressed */
    private function suppress(array &$suppressed, string $key, string $code, string $message, array $evidence = []): void
    {
        $suppressed[$key] = ['key' => $key, 'code' => $code, 'message' => $message] + $evidence;
    }

    /**
     * @param array<string, array<string, mixed>> $suppressed
     * @param array<string, array<string, mixed>> $datasets
     * @param array<string, array<string, mixed>> $states
     * @return array<string, array<string, mixed>>
     */
    private function decorateSuppressions(array $suppressed, array $datasets, array $states, DateTimeImmutable $cutoff, int $selectedHorizon): array
    {
        foreach ($suppressed as $key => &$item) {
            $sources = InsightRuleRegistry::sources()[$key] ?? [];
            $horizon = InsightRuleRegistry::comparisonHorizon($key, $selectedHorizon);
            $decisions = [];
            foreach ($sources as $metric) {
                $dataset = $datasets[$metric] ?? [];
                $baseline = is_array($dataset['monitoring_baseline'] ?? null) ? $dataset['monitoring_baseline'] : null;
                $baselineAt = isset($baseline['monitoring_baseline_at']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $baseline['monitoring_baseline_at']) : null;
                $decisions[$metric] = $this->activation->evaluate(
                    $cutoff,
                    $horizon,
                    $dataset['completed_dates'] ?? array_values(array_unique(array_map(static fn(array $point): string => (string) ($point['date'] ?? ''), $dataset['series'] ?? []))),
                    (string) ($states[$metric]['state'] ?? 'missing'),
                    $this->hasCurrentEvidence($dataset, $cutoff),
                    $this->sourceProvenance([$metric], $states),
                    $baselineAt === false ? null : $baselineAt,
                    is_array($baseline['evidence'] ?? null),
                );
            }
            $activationRank = [null => -1, ActivationState::CURRENT_STATE->value => 0, ActivationState::OBSERVED_MOVEMENT->value => 1, ActivationState::COMPARABLE_WINDOW->value => 2, ActivationState::CERTIFIED_PERIOD_COMPARISON->value => 3];
            $weakest = null;
            foreach ($decisions as $decision) {
                if ($weakest === null || ($activationRank[$decision->state?->value] ?? -1) < ($activationRank[$weakest->state?->value] ?? -1)) {
                    $weakest = $decision;
                }
            }
            $provenance = $sources === [] ? ProvenanceEvidence::uncertifiedReconstruction() : $this->sourceProvenance($sources, $states);
            $rule = InsightRuleRegistry::rules()[$key] ?? null;
            $item['activation_state'] = $weakest?->state?->value;
            $item['comparison_basis'] = $weakest?->comparisonBasis ?? '';
            $item['formula_version'] = InsightRuleRegistry::formulaVersion($key);
            $item['formula'] = InsightRuleRegistry::formulas()[$key] ?? '';
            [$currentFrom, $currentTo] = $this->period($cutoff, $horizon, 0);
            [$previousFrom, $previousTo] = $this->period($cutoff, $horizon, 1);
            $item['current_period'] = ['from' => $currentFrom, 'to' => $currentTo];
            $item['previous_period'] = ['from' => $previousFrom, 'to' => $previousTo];
            $item['materiality_outcome'] ??= 'suppressed:' . $item['code'];
            $item['absolute_gate'] ??= $rule['absoluteGate'] ?? null;
            $item['relative_gate_percent'] ??= InsightRuleRegistry::RELATIVE_GATE;
            $item['coverage'] = array_map(static fn(ActivationDecision $decision): array => $decision->toArray(), $decisions);
            $item['sources'] = $sources;
            $item += $provenance->toArray();
        }
        unset($item);
        return $suppressed;
    }

    /** @param list<array<string, mixed>> $readiness */
    private function hasIncompleteHistory(array $readiness): bool
    {
        return count(array_filter($readiness, static fn(array $item): bool => !$item['ready'])) > 0;
    }
}
