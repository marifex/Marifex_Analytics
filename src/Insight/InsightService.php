<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Insight;

use Config;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Marifex\Cron\AnalyticsCron;
use GlpiPlugin\Marifex\Metric\MetricQueryService;
use GlpiPlugin\Marifex\Security\EntityScope;

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
    ];

    private const TICKET_METRICS = [
        'historical_group_backlog', 'created_vs_resolved_tickets', 'unassigned_open_tickets', 'open_tickets_by_priority',
        'tickets_by_request_source', 'sla_breach_count', 'sla_breach_rate',
        'tickets_approaching_sla_breach', 'unsatisfied_survey_responses',
        'technician_workload_distribution', 'sla_breaches_by_technician',
    ];

    public function __construct(
        private readonly EntityScope $entityScope = new EntityScope(),
        private readonly InsightCalculator $calculator = new InsightCalculator(),
    ) {
    }

    /** @return array<string, mixed> */
    public function build(int $horizon, ?int $groupId = null, ?DateTimeImmutable $cutoff = null): array
    {
        $horizon = in_array($horizon, [7, 30, 90, 180, 365], true) ? $horizon : 30;
        $configuration = Config::getConfigurationValues('plugin:marifex');
        $timezone = new DateTimeZone((string) ($configuration['snapshot_timezone'] ?? 'UTC'));
        $cutoff ??= new DateTimeImmutable('yesterday', $timezone);
        $cutoff = $cutoff->setTime(0, 0);
        $historyDays = max(2 * $horizon, 60);
        $from = $cutoff->sub(new DateInterval('P' . $historyDays . 'D'));
        $query = new MetricQueryService($this->entityScope);
        $datasets = [];
        $states = [];
        $completedDates = $this->completedSnapshotDates($from, $cutoff);
        foreach (self::METRICS as $metric) {
            $scopedGroup = $metric === 'historical_open_backlog' ? $groupId : null;
            $datasets[$metric] = $query->query($metric, $from, $cutoff, $scopedGroup);
            $datasets[$metric]['completed_dates'] = $completedDates;
            $states[$metric] = $this->sourceState($metric, $cutoff);
            if ($groupId !== null && in_array($metric, self::TICKET_METRICS, true)) {
                $states[$metric]['state'] = 'unavailable';
                $states[$metric]['reason'] = 'The selected group filter has no certified group-grain source for this metric.';
            }
        }
        return $this->calculator->calculate($datasets, $states, $cutoff, $horizon, $groupId);
    }

    /** @return list<string> */
    private function completedSnapshotDates(DateTimeImmutable $from, DateTimeImmutable $cutoff): array
    {
        global $DB;
        $dates = [];
        foreach ($DB->request([
            'SELECT' => ['rollup_date'], 'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), [
                ['rollup_date' => ['>=', $from->format('Y-m-d')]], ['rollup_date' => ['<=', $cutoff->format('Y-m-d')]],
            ]),
            'GROUPBY' => ['rollup_date'], 'ORDER' => ['rollup_date ASC'],
        ]) as $row) $dates[] = (string) $row['rollup_date'];
        return $dates;
    }

    /** @return array<string, mixed> */
    private function sourceState(string $metric, DateTimeImmutable $cutoff): array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => [
                new QueryExpression('MAX(`rollup_date`) AS latest_date'),
                new QueryExpression('MAX(`date_mod`) AS completed_at'),
            ],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), ['metric_key' => $metric]),
        ])->current();
        $latest = $row && $row['latest_date'] ? (string) $row['latest_date'] : null;
        $completed = $row && $row['completed_at'] ? (string) $row['completed_at'] : null;
        if ($latest === null || $completed === null) return ['state' => 'missing', 'latest_date' => $latest, 'completed_at' => $completed];

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
        if (!$enabled) return ['state' => 'unavailable', 'latest_date' => $latest, 'completed_at' => $completed, 'expected_interval_seconds' => $frequency];
        $freshnessSeconds = (int) ceil(($frequency * 1.5) / 3600) * 3600;
        $deadline = (new DateTimeImmutable($completed, new DateTimeZone('UTC')))->add(new DateInterval('PT' . $freshnessSeconds . 'S'));
        $state = $latest < $cutoff->format('Y-m-d') || new DateTimeImmutable('now', new DateTimeZone('UTC')) > $deadline ? 'stale' : 'current';
        return [
            'state' => $state, 'latest_date' => $latest, 'completed_at' => $completed,
            'expected_interval_seconds' => $frequency, 'freshness_deadline' => $deadline->format(DATE_ATOM),
        ];
    }
}
