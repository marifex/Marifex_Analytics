<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Metric;

use DateTimeImmutable;
use DBmysql;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Marifex\Security\EntityScope;
use RuntimeException;

final class MetricQueryService
{
    public function __construct(
        private readonly EntityScope $entityScope = new EntityScope(),
        private readonly MetricRegistry $registry = new MetricRegistry(),
    ) {
    }

    /** @return array<string, mixed> */
    public function query(string $metricKey, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null, ?int $groupId = null): array
    {
        $definition = $this->registry->get($metricKey);

        return match ($definition->key) {
            'current_open_tickets' => $this->currentOpenTickets($definition, $groupId),
            'historical_open_backlog' => $this->historicalOpenBacklog(
                $definition,
                $from ?? new DateTimeImmutable('-30 days'),
                $to ?? new DateTimeImmutable('today'),
                $groupId
            ),
            'average_open_ticket_age' => $this->dailyRollupSeries(
                $definition,
                $from ?? new DateTimeImmutable('-30 days'),
                $to ?? new DateTimeImmutable('today')
            ),
            'historical_group_backlog' => $this->groupBacklogSeries(
                $definition,
                $from ?? new DateTimeImmutable('-30 days'),
                $to ?? new DateTimeImmutable('today')
            ),
            'asset_inventory_by_state',
            'open_tickets_by_priority',
            'sla_breaches_by_technician',
            'tickets_by_request_source',
            'created_vs_resolved_tickets',
            'technician_workload_distribution',
            'resolution_time_age_bands',
            'prohibited_software_installations',
            'unlicensed_software_installations',
            'incidents_by_operating_system',
            'repeat_incident_computers',
            'open_change_status_distribution',
            'open_problem_status_distribution' => $this->dimensionSeries(
                $definition,
                $from ?? new DateTimeImmutable('-30 days'),
                $to ?? new DateTimeImmutable('today')
            ),
            'asset_inventory_total',
            'stale_computer_inventory',
            'unassigned_open_tickets',
            'average_unassigned_time',
            'tickets_approaching_sla_breach',
            'sla_breach_count',
            'sla_breach_rate',
            'assignment_changes_per_ticket',
            'unsatisfied_survey_responses',
            'low_disk_capacity_computers',
            'computers_in_stock_over_30_days',
            'software_license_entitlements',
            'software_license_allocations',
            'software_license_overallocated_seats',
            'software_license_compliance_rate',
            'open_changes',
            'daily_change_volume',
            'daily_change_resolutions',
            'open_problems',
            'daily_problem_volume',
            'daily_problem_resolutions' => $this->dailyRollupSeries(
                $definition,
                $from ?? new DateTimeImmutable('-30 days'),
                $to ?? new DateTimeImmutable('today')
            ),
        };
    }

    /** @return array<string, mixed> */
    private function currentOpenTickets(MetricDefinition $definition, ?int $groupId): array
    {
        global $DB;
        $this->assertDatabase($DB);

        $criteria = array_merge(
            $this->entityScope->criteria(),
            ['is_deleted' => 0, 'status' => [1, 2, 3, 4]]
        );

        $query = [
            'SELECT' => [new QueryExpression($groupId === null ? 'COUNT(*) AS value' : 'COUNT(DISTINCT `glpi_tickets`.`id`) AS value')],
            'FROM' => 'glpi_tickets',
            'WHERE' => $criteria,
        ];
        if ($groupId !== null) {
            $query['INNER JOIN'] = ['glpi_groups_tickets' => ['ON' => ['glpi_groups_tickets' => 'tickets_id', 'glpi_tickets' => 'id']]];
            $query['WHERE']['glpi_groups_tickets.groups_id'] = $groupId;
            $query['WHERE']['glpi_groups_tickets.type'] = 2;
        }
        $row = $DB->request($query)->current();

        return [
            'metric' => $definition->key,
            'label' => __($definition->label, 'marifex'),
            'source' => $definition->source,
            'value' => (int) ($row['value'] ?? 0),
            'as_of' => gmdate(DATE_ATOM),
            'group_id' => $groupId,
        ];
    }

    /** @return array<string, mixed> */
    private function historicalOpenBacklog(
        MetricDefinition $definition,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $groupId,
    ): array {
        global $DB;
        $this->assertDatabase($DB);

        if ($from > $to || $from->diff($to)->days > 3660) {
            throw new RuntimeException('Invalid metric date range.');
        }

        if ($groupId === null) {
            return $this->dailyRollupSeries($definition, $from, $to);
        }
        global $DB;
        $iterator = $DB->request([
            'SELECT' => ['rollup_date', new QueryExpression('SUM(`metric_value`) AS value')],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), [
                'metric_key' => 'historical_group_backlog', 'dimension_key' => 'group', 'dimension_value' => (string) $groupId,
                ['rollup_date' => ['>=', $from->format('Y-m-d')]], ['rollup_date' => ['<=', $to->format('Y-m-d')]],
            ]),
            'GROUPBY' => ['rollup_date'], 'ORDER' => ['rollup_date ASC'],
        ]);
        $series = [];
        foreach ($iterator as $row) {
            $series[] = ['date' => $row['rollup_date'], 'value' => (int) $row['value']];
        }
        return ['metric' => $definition->key, 'label' => __($definition->label, 'marifex'), 'source' => $definition->source, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'group_id' => $groupId, 'series' => $series];
    }

    /** @return array<string, mixed> */
    private function dailyRollupSeries(MetricDefinition $definition, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        global $DB;
        $this->assertDatabase($DB);

        if ($from > $to || $from->diff($to)->days > 3660) {
            throw new RuntimeException('Invalid metric date range.');
        }

        $valueExpression = in_array($definition->format, ['duration_series', 'percentage_series', 'decimal_series'], true)
            ? 'SUM(`metric_value` * `sample_count`) / NULLIF(SUM(`sample_count`), 0) AS value'
            : 'SUM(`metric_value`) AS value';
        $iterator = $DB->request([
            'SELECT' => ['rollup_date', new QueryExpression($valueExpression)],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), [
                'metric_key' => $definition->key,
                ['rollup_date' => ['>=', $from->format('Y-m-d')]],
                ['rollup_date' => ['<=', $to->format('Y-m-d')]],
            ]),
            'GROUPBY' => ['rollup_date'],
            'ORDER' => ['rollup_date ASC'],
        ]);

        $series = [];
        foreach ($iterator as $row) {
            $series[] = [
                'date' => $row['rollup_date'],
                'value' => match ($definition->format) {
                    'percentage_series' => round((float) $row['value'], 1),
                    'decimal_series' => round((float) $row['value'], 2),
                    default => (int) $row['value'],
                },
            ];
        }

        return [
            'metric' => $definition->key,
            'label' => __($definition->label, 'marifex'),
            'source' => $definition->source,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'series' => $series,
        ];
    }

    private function assertDatabase(mixed $database): void
    {
        if (!$database instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }
    }

    /** @return array<string, mixed> */
    private function groupBacklogSeries(MetricDefinition $definition, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        global $DB;
        $this->assertDatabase($DB);
        if ($from > $to || $from->diff($to)->days > 3660) {
            throw new RuntimeException('Invalid metric date range.');
        }

        $rows = iterator_to_array($DB->request([
            'SELECT' => ['rollup_date', 'dimension_value', new QueryExpression('SUM(`metric_value`) AS value')],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), [
                'metric_key' => $definition->key,
                'dimension_key' => 'group',
                ['rollup_date' => ['>=', $from->format('Y-m-d')]],
                ['rollup_date' => ['<=', $to->format('Y-m-d')]],
            ]),
            'GROUPBY' => ['rollup_date', 'dimension_value'],
            'ORDER' => ['rollup_date ASC', 'dimension_value ASC'],
        ]));
        $groupIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['dimension_value'], $rows)));
        $groups = [];
        if ($groupIds !== []) {
            foreach ($DB->request(['SELECT' => ['id', 'completename', 'entities_id'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $groupIds]]) as $group) {
                $groups[(int) $group['id']] = [
                    'name' => (string) $group['completename'],
                    'entity_id' => (int) $group['entities_id'],
                ];
            }
        }
        $nameCounts = array_count_values(array_map(static fn (array $group): string => $group['name'], $groups));
        $entityIds = array_values(array_unique(array_column($groups, 'entity_id')));
        $entityNames = [];
        if ($entityIds !== []) {
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entityIds]]) as $entity) {
                $entityNames[(int) $entity['id']] = (string) $entity['completename'];
            }
        }
        $groupNames = [];
        $candidateNames = [];
        foreach ($groups as $groupId => $group) {
            $candidateNames[$groupId] = ($nameCounts[$group['name']] ?? 0) > 1
                ? sprintf('%s — %s', $group['name'], $entityNames[$group['entity_id']] ?? ('Entity #' . $group['entity_id']))
                : $group['name'];
        }
        $candidateCounts = array_count_values($candidateNames);
        foreach ($candidateNames as $groupId => $candidateName) {
            $groupNames[$groupId] = ($candidateCounts[$candidateName] ?? 0) > 1
                ? sprintf('%s · Group #%d', $candidateName, $groupId)
                : $candidateName;
        }
        $series = array_map(static function (array $row) use ($groupNames): array {
            $groupId = (int) $row['dimension_value'];
            return ['date' => $row['rollup_date'], 'dimension_id' => $groupId, 'dimension' => $groupNames[$groupId] ?? ('Group #' . $groupId), 'value' => (int) $row['value']];
        }, $rows);

        return ['metric' => $definition->key, 'label' => __($definition->label, 'marifex'), 'source' => $definition->source, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'series' => $series];
    }

    /** @return array<string, mixed> */
    private function dimensionSeries(MetricDefinition $definition, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        global $DB;
        $this->assertDatabase($DB);
        if ($from > $to || $from->diff($to)->days > 3660) {
            throw new RuntimeException('Invalid metric date range.');
        }
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['rollup_date', 'dimension_value', new QueryExpression('SUM(`metric_value`) AS value')],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), [
                'metric_key' => $definition->key,
                ['rollup_date' => ['>=', $from->format('Y-m-d')]],
                ['rollup_date' => ['<=', $to->format('Y-m-d')]],
            ]),
            'GROUPBY' => ['rollup_date', 'dimension_value'],
            'ORDER' => ['rollup_date ASC', 'dimension_value ASC'],
        ]));
        $dimensionIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['dimension_value'], $rows)));
        $labels = match ($definition->key) {
            'asset_inventory_by_state' => $this->stateLabels($dimensionIds),
            'open_tickets_by_priority' => $this->priorityLabels($dimensionIds),
            'sla_breaches_by_technician', 'technician_workload_distribution' => $this->userLabels($dimensionIds),
            'tickets_by_request_source' => $this->tableLabels('glpi_requesttypes', $dimensionIds, 'Request source'),
            'created_vs_resolved_tickets' => [1 => __('Created', 'marifex'), 2 => __('Resolved', 'marifex')],
            'resolution_time_age_bands' => [1 => __('< 1 day', 'marifex'), 2 => __('1-3 days', 'marifex'), 3 => __('3-7 days', 'marifex'), 4 => __('7-30 days', 'marifex'), 5 => __('30+ days', 'marifex')],
            'prohibited_software_installations', 'unlicensed_software_installations' => $this->tableLabels('glpi_softwares', $dimensionIds, 'Software'),
            'incidents_by_operating_system' => $this->tableLabels('glpi_operatingsystems', $dimensionIds, 'Operating system'),
            'repeat_incident_computers' => $this->tableLabels('glpi_computers', $dimensionIds, 'Computer'),
            'open_change_status_distribution' => $this->statusLabels('Change', $dimensionIds),
            'open_problem_status_distribution' => $this->statusLabels('Problem', $dimensionIds),
            default => [],
        };
        $series = array_map(static function (array $row) use ($labels): array {
            $dimensionId = (int) $row['dimension_value'];
            return [
                'date' => $row['rollup_date'],
                'dimension_id' => $dimensionId,
                'dimension' => $labels[$dimensionId] ?? ('Value #' . $dimensionId),
                'value' => (int) $row['value'],
            ];
        }, $rows);
        return [
            'metric' => $definition->key,
            'label' => __($definition->label, 'marifex'),
            'source' => $definition->source,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'series' => $series,
        ];
    }

    /** @param list<int> $stateIds
     *  @return array<int, string>
     */
    private function stateLabels(array $stateIds): array
    {
        global $DB;
        $labels = [0 => __('Unspecified', 'marifex')];
        if ($stateIds === []) {
            return $labels;
        }
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_states', 'WHERE' => ['id' => $stateIds]]) as $state) {
            $labels[(int) $state['id']] = (string) $state['completename'];
        }
        $counts = array_count_values($labels);
        foreach ($labels as $id => $label) {
            if (($counts[$label] ?? 0) > 1) {
                $labels[$id] = sprintf('%s · State #%d', $label, $id);
            }
        }
        return $labels;
    }

    /** @param list<int> $ids
     *  @return array<int, string>
     */
    private function priorityLabels(array $ids): array
    {
        $known = [1 => __('Very low', 'marifex'), 2 => __('Low', 'marifex'), 3 => __('Medium', 'marifex'), 4 => __('High', 'marifex'), 5 => __('Very high', 'marifex'), 6 => __('Major', 'marifex')];
        return array_intersect_key($known, array_flip($ids));
    }

    /** @param list<int> $ids
     *  @return array<int, string>
     */
    private function userLabels(array $ids): array
    {
        global $DB;
        $labels = [];
        if ($ids === []) {
            return $labels;
        }
        foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $ids]]) as $user) {
            $fullName = trim((string) $user['firstname'] . ' ' . (string) $user['realname']);
            $labels[(int) $user['id']] = $fullName !== '' ? $fullName : (string) $user['name'];
        }
        return $labels;
    }

    /** @param list<int> $ids
     *  @return array<int, string>
     */
    private function tableLabels(string $table, array $ids, string $fallback): array
    {
        global $DB;
        $labels = [0 => __('Unspecified', 'marifex')];
        $positiveIds = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($positiveIds !== []) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => $table, 'WHERE' => ['id' => $positiveIds]]) as $row) {
                $labels[(int) $row['id']] = (string) $row['name'];
            }
        }
        foreach ($ids as $id) {
            $labels[$id] ??= sprintf('%s #%d', $fallback, $id);
        }
        return $labels;
    }

    /** @param list<int> $statusIds
     *  @return array<int, string>
     */
    private function statusLabels(string $domain, array $statusIds): array
    {
        $known = [
            1 => __('New', 'marifex'),
            2 => __('Assigned', 'marifex'),
            3 => __('Planned', 'marifex'),
            4 => __('Pending', 'marifex'),
            5 => __('Solved', 'marifex'),
            6 => __('Closed', 'marifex'),
        ];
        $labels = [];
        foreach ($statusIds as $id) {
            $labels[$id] = $known[$id] ?? sprintf('%s status #%d', $domain, $id);
        }
        return $labels;
    }
}
