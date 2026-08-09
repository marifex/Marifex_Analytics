<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Metric;

use DateTimeImmutable;
use DBmysql;
use CommonITILValidation;
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
            'latest_solution_refused_tickets' => $this->latestSolutionRefusedTickets($definition),
            'active_sla_exceptions' => $this->activeSlaExceptions($definition),
            'operational_attention' => $this->operationalAttention($definition),
            'open_tickets_priority_category_matrix' => $this->priorityCategoryMatrix($definition, $from ?? new DateTimeImmutable('-30 days'), $to ?? new DateTimeImmutable('today')),
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
            'open_incidents_by_assignment_group',
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
    private function latestSolutionRefusedTickets(MetricDefinition $definition): array
    {
        global $DB;
        $tickets = $this->openTicketRows();
        $ticketIds = array_keys($tickets);
        $latest = [];
        if ($ticketIds !== []) {
            foreach ($DB->request([
                'SELECT' => ['id', 'items_id', 'status', 'date_creation'],
                'FROM' => 'glpi_itilsolutions',
                'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $ticketIds],
                'ORDER' => ['items_id ASC', 'id ASC'],
            ]) as $solution) {
                $latest[(int) $solution['items_id']] = $solution;
            }
        }
        $rows = [];
        foreach ($latest as $ticketId => $solution) {
            if ((int) $solution['status'] !== CommonITILValidation::REFUSED || !isset($tickets[$ticketId])) {
                continue;
            }
            $ticket = $tickets[$ticketId];
            $rows[] = $this->ticketListRow($ticket) + ['latest_solution_date' => (string) $solution['date_creation']];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($b['latest_solution_date'], $a['latest_solution_date']));
        return $this->recordList($definition, $rows);
    }

    /** @return array<string, mixed> */
    private function activeSlaExceptions(MetricDefinition $definition): array
    {
        $now = new DateTimeImmutable('now');
        $approaching = $now->modify('+1 day');
        $rows = [];
        foreach ($this->openTicketRows() as $ticket) {
            $deadlineValue = (string) ($ticket['time_to_resolve'] ?? '');
            if ($deadlineValue === '' || (int) ($ticket['slas_id_ttr'] ?? 0) < 1) {
                continue;
            }
            $deadline = new DateTimeImmutable($deadlineValue);
            if ($deadline > $approaching) {
                continue;
            }
            $seconds = $deadline->getTimestamp() - $now->getTimestamp();
            $rows[] = $this->ticketListRow($ticket) + [
                'deadline' => $deadlineValue,
                'state' => $seconds < 0 ? 'Breached' : 'Approaching',
                'seconds' => $seconds,
                'timing' => $seconds < 0 ? sprintf('%s overdue', $this->compactDuration(abs($seconds))) : sprintf('%s remaining', $this->compactDuration($seconds)),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['state'] !== $b['state']) return $a['state'] === 'Breached' ? -1 : 1;
            return $a['state'] === 'Breached' ? $a['seconds'] <=> $b['seconds'] : $a['seconds'] <=> $b['seconds'];
        });
        $result = $this->recordList($definition, $rows);
        $result['breached_count'] = count(array_filter($rows, static fn(array $row): bool => $row['state'] === 'Breached'));
        $result['approaching_count'] = count($rows) - $result['breached_count'];
        return $result;
    }

    /** @return array<string, mixed> */
    private function operationalAttention(MetricDefinition $definition): array
    {
        $sla = $this->activeSlaExceptions(new MetricDefinition('active_sla_exceptions', 'Active SLA exceptions', 'live', 'record_list'));
        $breached = (int) $sla['breached_count'];
        $approaching = (int) $sla['approaching_count'];
        $items = [
            ['finding' => 'Open SLA breaches', 'count' => $breached, 'severity' => 'critical', 'target' => 'tickets'],
            ['finding' => 'Tickets approaching SLA breach', 'count' => $approaching, 'severity' => 'warning', 'target' => 'tickets'],
            ['finding' => 'Unassigned open tickets', 'count' => $this->latestRollupValue('unassigned_open_tickets'), 'severity' => 'warning', 'target' => 'tickets'],
            ['finding' => 'Unsatisfied survey responses', 'count' => $this->latestRollupValue('unsatisfied_survey_responses'), 'severity' => 'critical', 'target' => 'tickets'],
            ['finding' => 'Computers with stale inventory', 'count' => $this->latestRollupValue('stale_computer_inventory'), 'severity' => 'warning', 'target' => 'assets'],
            ['finding' => 'Computers with low disk capacity', 'count' => $this->latestRollupValue('low_disk_capacity_computers'), 'severity' => 'critical', 'target' => 'assets'],
            ['finding' => 'Computers in stock over 30 days', 'count' => $this->latestRollupValue('computers_in_stock_over_30_days'), 'severity' => 'info', 'target' => 'assets'],
            ['finding' => 'Invalid software installations', 'count' => $this->latestRollupValue('prohibited_software_installations'), 'severity' => 'critical', 'target' => 'licences'],
            ['finding' => 'Installations above entitlement', 'count' => $this->latestRollupValue('unlicensed_software_installations'), 'severity' => 'critical', 'target' => 'licences'],
            ['finding' => 'Computers with repeat incidents', 'count' => $this->latestRollupValue('repeat_incident_computers'), 'severity' => 'warning', 'target' => 'assets'],
        ];
        usort($items, static fn(array $a, array $b): int => ['critical' => 0, 'warning' => 1, 'info' => 2][$a['severity']] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$b['severity']] ?: $b['count'] <=> $a['count']);
        return ['metric' => $definition->key, 'label' => __($definition->label, 'marifex'), 'source' => $definition->source, 'rows' => $items, 'as_of' => gmdate(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function priorityCategoryMatrix(MetricDefinition $definition, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        global $DB;
        $raw = iterator_to_array($DB->request([
            'SELECT' => ['rollup_date', 'row_value', 'column_value', new QueryExpression('SUM(`metric_value`) AS value')],
            'FROM' => 'glpi_plugin_marifex_daily_matrix_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), [
                'metric_key' => $definition->key,
                ['rollup_date' => ['>=' , $from->format('Y-m-d')]], ['rollup_date' => ['<=' , $to->format('Y-m-d')]],
            ]),
            'GROUPBY' => ['rollup_date', 'row_value', 'column_value'], 'ORDER' => ['rollup_date ASC'],
        ]), false);
        $latest = $raw === [] ? null : (string) end($raw)['rollup_date'];
        $raw = array_values(array_filter($raw, static fn(array $row): bool => (string) $row['rollup_date'] === $latest));
        $categoryIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['column_value'], $raw)));
        $categories = [0 => 'Uncategorised'];
        if ($categoryIds !== []) {
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories', 'WHERE' => ['id' => $categoryIds]]) as $category) {
                $categories[(int) $category['id']] = (string) $category['completename'];
            }
        }
        $priorityLabels = [1 => 'Very low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very high', 6 => 'Major'];
        return [
            'metric' => $definition->key, 'label' => __($definition->label, 'marifex'), 'source' => $definition->source,
            'as_of' => $latest,
            'matrix' => array_map(static fn(array $row): array => [
                'row_id' => (int) $row['row_value'], 'row' => $priorityLabels[(int) $row['row_value']] ?? ('Priority ' . $row['row_value']),
                'column_id' => (int) $row['column_value'], 'column' => $categories[(int) $row['column_value']] ?? ('Category #' . $row['column_value']),
                'value' => (int) $row['value'],
            ], $raw),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function openTicketRows(): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'status', 'priority', 'slas_id_ttr', 'time_to_resolve'],
            'FROM' => 'glpi_tickets',
            'WHERE' => array_merge($this->entityScope->criteria(), ['is_deleted' => 0, 'status' => [1, 2, 3, 4]]),
        ]) as $ticket) {
            $rows[(int) $ticket['id']] = $ticket;
        }
        $groups = [];
        if ($rows !== []) {
            foreach ($DB->request(['SELECT' => ['tickets_id', 'groups_id'], 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => array_keys($rows), 'type' => 2]]) as $assignment) {
                $groups[(int) $assignment['tickets_id']][] = (int) $assignment['groups_id'];
            }
        }
        $groupNames = [];
        $ids = array_values(array_unique(array_merge(...array_values($groups ?: [[]]))));
        if ($ids !== []) foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $ids]]) as $group) $groupNames[(int) $group['id']] = (string) $group['completename'];
        foreach ($rows as $id => &$row) $row['group'] = implode(', ', array_map(static fn(int $groupId): string => $groupNames[$groupId] ?? ('Group #' . $groupId), $groups[$id] ?? [])) ?: 'Unassigned';
        unset($row);
        return $rows;
    }

    /** @param array<string, mixed> $ticket @return array<string, mixed> */
    private function ticketListRow(array $ticket): array
    {
        return ['id' => (int) $ticket['id'], 'title' => (string) $ticket['name'], 'priority' => (int) $ticket['priority'], 'status' => (int) $ticket['status'], 'group' => (string) $ticket['group'], 'link' => '/front/ticket.form.php?id=' . (int) $ticket['id']];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function recordList(MetricDefinition $definition, array $rows): array
    {
        return ['metric' => $definition->key, 'label' => __($definition->label, 'marifex'), 'source' => $definition->source, 'value' => count($rows), 'rows' => array_slice($rows, 0, 100), 'as_of' => gmdate(DATE_ATOM)];
    }

    private function compactDuration(int $seconds): string
    {
        if ($seconds >= 86400) return round($seconds / 86400, 1) . 'd';
        if ($seconds >= 3600) return round($seconds / 3600, 1) . 'h';
        return max(1, (int) ceil($seconds / 60)) . 'm';
    }

    private function latestRollupValue(string $metric): int
    {
        global $DB;
        $value = 0;
        $latest = null;
        foreach ($DB->request([
            'SELECT' => ['rollup_date', 'metric_value'], 'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => array_merge($this->entityScope->criteria(), ['metric_key' => $metric]),
            'ORDER' => ['rollup_date DESC'],
        ]) as $row) {
            $latest ??= (string) $row['rollup_date'];
            if ((string) $row['rollup_date'] !== $latest) break;
            $value += (int) $row['metric_value'];
        }
        return $value;
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
            'open_incidents_by_assignment_group' => $this->groupDimensionLabels($dimensionIds),
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

    /** @param list<int> $ids @return array<int, string> */
    private function groupDimensionLabels(array $ids): array
    {
        global $DB;
        $labels = [0 => __('Unassigned', 'marifex')];
        $positive = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($positive === []) return $labels;
        $groups = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename', 'entities_id'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $positive]]) as $group) {
            $groups[(int) $group['id']] = ['name' => (string) $group['completename'], 'entity' => (int) $group['entities_id']];
        }
        $entities = [];
        $entityIds = array_values(array_unique(array_column($groups, 'entity')));
        if ($entityIds !== []) foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entityIds]]) as $entity) $entities[(int) $entity['id']] = (string) $entity['completename'];
        $nameCounts = array_count_values(array_column($groups, 'name'));
        foreach ($groups as $id => $group) {
            $labels[$id] = ($nameCounts[$group['name']] ?? 0) > 1
                ? sprintf('%s — %s · Group #%d', $group['name'], $entities[$group['entity']] ?? ('Entity #' . $group['entity']), $id)
                : $group['name'];
        }
        return $labels;
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
