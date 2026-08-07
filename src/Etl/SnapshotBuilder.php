<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use DateTimeImmutable;
use Glpi\DBAL\QueryExpression;

final class SnapshotBuilder
{
    public function run(?DateTimeImmutable $date = null): int
    {
        global $DB;
        $date ??= new DateTimeImmutable('today');
        $snapshotDate = $date->format('Y-m-d');
        $processed = 0;

        $tickets = $DB->request([
            'SELECT' => ['id', 'entities_id', 'status', 'priority', 'date'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0, 'status' => [1, 2, 3, 4]],
        ]);

        foreach ($tickets as $ticket) {
            $created = new DateTimeImmutable($ticket['date']);
            $DB->updateOrInsert('glpi_plugin_marifex_daily_snapshots', [
                'entities_id' => (int) $ticket['entities_id'],
                'status' => (int) $ticket['status'],
                'priority' => (int) $ticket['priority'],
                'age_seconds' => max(0, $date->getTimestamp() - $created->getTimestamp()),
                'is_open' => 1,
            ], ['snapshot_date' => $snapshotDate, 'tickets_id' => (int) $ticket['id']]);
            ++$processed;
        }

        $counts = $DB->request([
            'SELECT' => ['entities_id', new QueryExpression('COUNT(*) AS value')],
            'FROM' => 'glpi_plugin_marifex_daily_snapshots',
            'WHERE' => ['snapshot_date' => $snapshotDate, 'is_open' => 1],
            'GROUPBY' => ['entities_id'],
        ]);
        foreach ($counts as $row) {
            $DB->updateOrInsert('glpi_plugin_marifex_daily_rollups', [
                'metric_value' => (int) $row['value'], 'sample_count' => (int) $row['value'],
            ], [
                'rollup_date' => $snapshotDate,
                'entities_id' => (int) $row['entities_id'],
                'metric_key' => 'historical_open_backlog',
                'dimension_key' => '',
                'dimension_value' => '',
            ]);
        }

        return $processed;
    }
}
