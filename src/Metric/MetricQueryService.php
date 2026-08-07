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
    public function query(string $metricKey, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $definition = $this->registry->get($metricKey);

        return match ($definition->key) {
            'current_open_tickets' => $this->currentOpenTickets($definition),
            'historical_open_backlog' => $this->historicalOpenBacklog(
                $definition,
                $from ?? new DateTimeImmutable('-30 days'),
                $to ?? new DateTimeImmutable('today')
            ),
            'average_open_ticket_age' => $this->dailyRollupSeries(
                $definition,
                $from ?? new DateTimeImmutable('-30 days'),
                $to ?? new DateTimeImmutable('today')
            ),
        };
    }

    /** @return array<string, mixed> */
    private function currentOpenTickets(MetricDefinition $definition): array
    {
        global $DB;
        $this->assertDatabase($DB);

        $criteria = array_merge(
            $this->entityScope->criteria(),
            ['is_deleted' => 0, 'status' => [1, 2, 3, 4]]
        );

        $row = $DB->request([
            'SELECT' => [new QueryExpression('COUNT(*) AS value')],
            'FROM' => 'glpi_tickets',
            'WHERE' => $criteria,
        ])->current();

        return [
            'metric' => $definition->key,
            'label' => __($definition->label, 'marifex'),
            'source' => $definition->source,
            'value' => (int) ($row['value'] ?? 0),
            'as_of' => gmdate(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function historicalOpenBacklog(
        MetricDefinition $definition,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        global $DB;
        $this->assertDatabase($DB);

        if ($from > $to || $from->diff($to)->days > 3660) {
            throw new RuntimeException('Invalid metric date range.');
        }

        return $this->dailyRollupSeries($definition, $from, $to);
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
}
