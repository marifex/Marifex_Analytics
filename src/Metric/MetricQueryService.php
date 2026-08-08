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

        $valueExpression = $definition->format === 'duration_series'
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
            $series[] = ['date' => $row['rollup_date'], 'value' => (int) $row['value']];
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
        $groupNames = [];
        if ($groupIds !== []) {
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $groupIds]]) as $group) {
                $groupNames[(int) $group['id']] = (string) $group['completename'];
            }
        }
        $series = array_map(static function (array $row) use ($groupNames): array {
            $groupId = (int) $row['dimension_value'];
            return ['date' => $row['rollup_date'], 'dimension_id' => $groupId, 'dimension' => $groupNames[$groupId] ?? ('Group #' . $groupId), 'value' => (int) $row['value']];
        }, $rows);

        return ['metric' => $definition->key, 'label' => __($definition->label, 'marifex'), 'source' => $definition->source, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'series' => $series];
    }
}
