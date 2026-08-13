<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Insight;

use Config;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Marifex\Analytics\MonitoringBaselineRepository;
use GlpiPlugin\Marifex\Analytics\MonitoringScope;
use GlpiPlugin\Marifex\Analytics\ProvenanceEvidence;
use GlpiPlugin\Marifex\Cron\AnalyticsCron;
use GlpiPlugin\Marifex\Metric\MetricQueryService;
use GlpiPlugin\Marifex\Metric\MetricRegistry;
use GlpiPlugin\Marifex\Security\EntityScope;
use RuntimeException;

final class InsightService
{
    private const METRICS = [
        'historical_open_backlog', 'created_vs_resolved_tickets', 'unassigned_open_tickets',
        'open_tickets_by_priority', 'historical_group_backlog', 'tickets_by_request_source',
        'stale_computer_inventory', 'asset_inventory_total', 'daily_change_volume',
        'daily_change_resolutions', 'daily_problem_volume', 'daily_problem_resolutions',
        'sla_breach_count', 'sla_breach_rate', 'tickets_approaching_sla_breach',
        'unsatisfied_survey_responses', 'repeat_incident_computers',
        'software_license_overallocated_seats', 'software_license_compliance_rate',
        'technician_workload_distribution', 'sla_breaches_by_technician',
        'created_tickets_by_request_source', 'ticket_reopen_events', 'ticket_resolution_events',
        'first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds',
        'survey_responses_total', 'dissatisfied_responses_total', 'customer_dissatisfaction_rate',
        'solution_proposed_tickets', 'solution_refused_tickets', 'refused_solution_rate',
        'incident_linked_computers', 'repeat_incident_computers_90d', 'repeat_incident_asset_rate',
        'licence_covered_titles', 'licence_installed_titles', 'licence_utilization_rate', 'licence_coverage_gap_rate',
    ];

    private const TICKET_METRICS = [
        'historical_group_backlog', 'created_vs_resolved_tickets', 'unassigned_open_tickets', 'open_tickets_by_priority',
        'tickets_by_request_source', 'sla_breach_count', 'sla_breach_rate',
        'tickets_approaching_sla_breach', 'unsatisfied_survey_responses',
        'technician_workload_distribution', 'sla_breaches_by_technician',
        'created_tickets_by_request_source', 'ticket_reopen_events', 'ticket_resolution_events',
        'first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds',
        'survey_responses_total', 'dissatisfied_responses_total', 'customer_dissatisfaction_rate',
        'solution_proposed_tickets', 'solution_refused_tickets', 'refused_solution_rate',
    ];

    public function __construct(
        private readonly EntityScope $entityScope = new EntityScope(),
        private readonly InsightCalculator $calculator = new InsightCalculator(),
        private readonly MetricRegistry $metricRegistry = new MetricRegistry(),
        private readonly MonitoringBaselineRepository $baselines = new MonitoringBaselineRepository(),
    ) {
    }

    /** @return array<string, mixed> */
    public function build(int $horizon, ?int $groupId = null, ?DateTimeImmutable $cutoff = null, array $domains = []): array
    {
        $horizon = in_array($horizon, [7, 30, 90, 180, 365], true) ? $horizon : 30;
        if ($groupId !== null) {
            $this->assertAuthorizedGroup($groupId);
        }
        $configuration = Config::getConfigurationValues('plugin:marifex');
        $timezone = new DateTimeZone((string) ($configuration['snapshot_timezone'] ?? 'UTC'));
        $cutoff ??= new DateTimeImmutable('yesterday', $timezone);
        $cutoff = $cutoff->setTime(0, 0);
        $historyDays = max(2 * $horizon, 180);
        $from = $cutoff->sub(new DateInterval('P' . $historyDays . 'D'));
        $query = new MetricQueryService($this->entityScope);
        $datasets = [];
        $states = [];
        foreach (self::METRICS as $metric) {
            $scopedGroup = $metric === 'historical_open_backlog' ? $groupId : null;
            $datasets[$metric] = $query->query($metric, $from, $cutoff, $scopedGroup);
            $datasets[$metric]['completed_dates'] = $this->completedSnapshotDates($metric, $from, $cutoff, $scopedGroup);
            $datasets[$metric]['current_observation_complete'] = in_array(
                $cutoff->format('Y-m-d'),
                $datasets[$metric]['completed_dates'],
                true,
            );
            $datasets[$metric]['monitoring_baseline'] = $this->monitoringBaseline($metric, $scopedGroup);
            $states[$metric] = $this->sourceState($metric, $cutoff, $scopedGroup);
            if ($groupId !== null && in_array($metric, self::TICKET_METRICS, true)) {
                $states[$metric]['state'] = 'unavailable';
                $states[$metric]['reason'] = 'The selected group filter has no certified group-grain source for this metric.';
            }
        }
        $result = $this->calculator->calculate($datasets, $states, $cutoff, $horizon, $groupId, $domains);
        $scope = [
            'root_entity_id' => $this->entityScope->activeEntityId(),
            'entity_ids' => $this->entityScope->activeEntityIds(),
            'recursive' => $this->entityScope->isRecursive(),
            'group_id' => $groupId,
        ];
        $result['scope'] = $scope;
        foreach ($result['insights'] as &$insight) {
            $comparisonHorizon = (int) ($insight['comparison_horizon_days'] ?? $horizon);
            $currentFrom = $cutoff->sub(new DateInterval('P' . ($comparisonHorizon - 1) . 'D'));
            $previousTo = $currentFrom->sub(new DateInterval('P1D'));
            $previousFrom = $previousTo->sub(new DateInterval('P' . ($comparisonHorizon - 1) . 'D'));
            $insight['calculation']['scope'] = $scope;
            $insight['calculation']['coverage'] = $result['readiness']['metrics'];
            $insight['calculation']['last_refresh'] = $insight['as_of'] ?? null;
            $insight['calculation']['current_period'] = ['from' => $currentFrom->format('Y-m-d'), 'to' => $cutoff->format('Y-m-d')];
            $insight['calculation']['previous_period'] = ['from' => $previousFrom->format('Y-m-d'), 'to' => $previousTo->format('Y-m-d')];
        }
        unset($insight);
        foreach ($result['suppressed'] as &$suppression) {
            $suppression['scope'] = $scope;
            $suppression['last_refresh'] = $states[$suppression['sources'][0] ?? '']['completed_at'] ?? null;
        }
        unset($suppression);
        return $result;
    }

    private function assertAuthorizedGroup(int $groupId): void
    {
        global $DB;
        $group = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_groups',
            'WHERE' => ['id' => $groupId, 'entities_id' => $this->entityScope->activeEntityIds()],
            'LIMIT' => 1,
        ])->current();
        if (!$group) {
            throw new RuntimeException('The selected group is not available in the active entity scope.');
        }
    }

    /** @return list<string> */
    private function completedSnapshotDates(string $metric, DateTimeImmutable $from, DateTimeImmutable $cutoff, ?int $groupId): array
    {
        global $DB;
        $entityIds = $this->entityScope->activeEntityIds();
        $datesByEntity = [];
        if ($DB->tableExists('glpi_plugin_marifex_daily_metric_observations')) {
            $observationScope = $groupId === null ? $this->entityScope->criteria() : [];
            foreach ($DB->request([
                'SELECT' => ['observation_date', 'entities_id'],
                'FROM' => 'glpi_plugin_marifex_daily_metric_observations',
                'WHERE' => array_merge($observationScope, [
                    'metric_key' => $metric, 'groups_id' => $groupId ?? 0, 'provenance' => 'OBSERVED',
                    ['observation_date' => ['>=', $from->format('Y-m-d')]], ['observation_date' => ['<=', $cutoff->format('Y-m-d')]],
                ]),
            ]) as $row) {
                $datesByEntity[(string) $row['observation_date']][(int) $row['entities_id']] = true;
            }
        }
        $where = array_merge($this->entityScope->criteria(), [
            'metric_key' => $groupId === null ? $metric : 'historical_group_backlog',
            ['rollup_date' => ['>=', $from->format('Y-m-d')]], ['rollup_date' => ['<=', $cutoff->format('Y-m-d')]],
        ]);
        if ($groupId !== null) {
            $where['dimension_key'] = 'group';
            $where['dimension_value'] = (string) $groupId;
        }
        foreach ($DB->request([
            'SELECT' => ['rollup_date', 'entities_id'], 'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => $where,
            'GROUPBY' => ['rollup_date', 'entities_id'], 'ORDER' => ['rollup_date ASC'],
        ]) as $row) $datesByEntity[(string) $row['rollup_date']][(int) $row['entities_id']] = true;
        $dates = [];
        foreach ($datesByEntity as $date => $observedEntities) {
            if (($groupId !== null && $observedEntities !== []) || array_diff($entityIds, array_map('intval', array_keys($observedEntities))) === []) {
                $dates[] = $date;
            }
        }
        sort($dates, SORT_STRING);
        return $dates;
    }

    /** @return array<string, mixed>|null */
    private function monitoringBaseline(string $metric, ?int $groupId): ?array
    {
        $format = $this->metricRegistry->get($metric)->format;
        $grain = in_array($format, ['dimension_series', 'matrix'], true) ? 'dimension' : 'scalar';
        return $this->baselines->find(new MonitoringScope(
            $this->entityScope->activeEntityId(),
            $this->entityScope->isRecursive(),
            $this->entityScope->activeEntityIds(),
            $groupId,
            $metric,
            $grain,
        ));
    }

    /** @return array<string, mixed> */
    private function sourceState(string $metric, DateTimeImmutable $cutoff, ?int $groupId): array
    {
        global $DB;
        $manifestLatest = [];
        $manifestCompleted = [];
        if ($DB->tableExists('glpi_plugin_marifex_daily_metric_observations')) {
            $observationScope = $groupId === null ? $this->entityScope->criteria() : [];
            foreach ($DB->request([
                'SELECT' => ['entities_id', new QueryExpression('MAX(`observation_date`) AS latest_date'), new QueryExpression('MAX(`completed_at`) AS completed_at')],
                'FROM' => 'glpi_plugin_marifex_daily_metric_observations',
                'WHERE' => array_merge($observationScope, ['metric_key' => $metric, 'groups_id' => $groupId ?? 0, 'provenance' => 'OBSERVED']),
                'GROUPBY' => ['entities_id'],
            ]) as $observation) {
                $manifestLatest[(int) $observation['entities_id']] = (string) $observation['latest_date'];
                $manifestCompleted[(int) $observation['entities_id']] = (string) $observation['completed_at'];
            }
        }
        $entityIds = $this->entityScope->activeEntityIds();
        $manifestComplete = $groupId !== null ? $manifestLatest !== [] : array_diff($entityIds, array_keys($manifestLatest)) === [];
        $row = $DB->request([
            'SELECT' => [
                new QueryExpression('MAX(`rollup_date`) AS latest_date'),
                new QueryExpression('MAX(`date_mod`) AS completed_at'),
            ],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), [
                'metric_key' => $groupId === null ? $metric : 'historical_group_backlog',
            ] + ($groupId === null ? [] : ['dimension_key' => 'group', 'dimension_value' => (string) $groupId])),
        ])->current();
        $latest = $manifestComplete ? min($manifestLatest) : ($row && $row['latest_date'] ? (string) $row['latest_date'] : null);
        $completed = $manifestComplete ? min($manifestCompleted) : ($row && $row['completed_at'] ? (string) $row['completed_at'] : null);
        $provenance = ProvenanceEvidence::observed()->toArray();
        if ($latest === null || $completed === null) return ['state' => 'missing', 'latest_date' => $latest, 'completed_at' => $completed] + $provenance;

        $frequency = DAY_TIMESTAMP;
        $enabled = true;
        if ($DB->tableExists('glpi_crontasks')) {
            $cron = $DB->request([
                'SELECT' => ['state', 'frequency'], 'FROM' => 'glpi_crontasks',
                'WHERE' => ['itemtype' => AnalyticsCron::class, 'name' => 'dailySnapshot'], 'LIMIT' => 1,
            ])->current();
            if ($cron) {
                $frequency = max(3600, (int) $cron['frequency']);
                $enabled = (int) $cron['state'] !== 0;
            }
        }
        if (!$enabled) return ['state' => 'unavailable', 'latest_date' => $latest, 'completed_at' => $completed, 'expected_interval_seconds' => $frequency] + $provenance;
        $freshnessSeconds = (int) ceil(($frequency * 1.5) / 3600) * 3600;
        $deadline = (new DateTimeImmutable($completed, new DateTimeZone('UTC')))->add(new DateInterval('PT' . $freshnessSeconds . 'S'));
        $state = $latest < $cutoff->format('Y-m-d') || new DateTimeImmutable('now', new DateTimeZone('UTC')) > $deadline ? 'stale' : 'current';
        return [
            'state' => $state, 'latest_date' => $latest, 'completed_at' => $completed,
            'expected_interval_seconds' => $frequency, 'freshness_deadline' => $deadline->format(DATE_ATOM),
        ] + $provenance;
    }
}
