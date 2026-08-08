<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use Config;
use DateTimeImmutable;
use DateTimeZone;
use Glpi\DBAL\QueryExpression;

final class SnapshotBuilder
{
    public function run(?DateTimeImmutable $date = null): int
    {
        global $DB;
        $config = Config::getConfigurationValues('plugin:marifex');
        $timezone = new DateTimeZone((string) ($config['snapshot_timezone'] ?? 'UTC'));
        $date ??= new DateTimeImmutable('yesterday', $timezone);
        $localDay = $date->setTimezone($timezone)->setTime(0, 0);
        $snapshotDate = $localDay->format('Y-m-d');
        $cutoffUtc = $localDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');

        $DB->delete('glpi_plugin_marifex_daily_snapshots', ['snapshot_date' => $snapshotDate]);
        $DB->delete('glpi_plugin_marifex_daily_rollups', ['rollup_date' => $snapshotDate]);

        $intervals = $DB->request([
            'SELECT' => ['tickets_id', 'entities_id', 'state_value', 'started_at'],
            'FROM' => 'glpi_plugin_marifex_state_intervals',
            'WHERE' => [
                'state_type' => 'status',
                'state_value' => ['1', '2', '3', '4'],
                ['started_at' => ['<', $cutoff]],
                ['OR' => [['ended_at' => null], ['ended_at' => ['>', $cutoff]]]],
            ],
        ]);

        $intervalRows = iterator_to_array($intervals);
        $ticketIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['tickets_id'], $intervalRows)));
        $createdDates = [];
        if ($ticketIds !== []) {
            foreach ($DB->request([
                'SELECT' => ['id', 'date', 'date_creation', 'date_mod'],
                'FROM' => 'glpi_tickets',
                'WHERE' => ['id' => $ticketIds],
            ]) as $ticket) {
                $createdDates[(int) $ticket['id']] = (string) ($ticket['date'] ?: $ticket['date_creation'] ?: $ticket['date_mod']);
            }
        }

        $processed = 0;
        foreach ($intervalRows as $interval) {
            $ticketId = (int) $interval['tickets_id'];
            if (!isset($createdDates[$ticketId])) {
                continue;
            }
            $createdAt = new DateTimeImmutable($createdDates[$ticketId], new DateTimeZone('UTC'));
            $DB->insert('glpi_plugin_marifex_daily_snapshots', [
                'snapshot_date' => $snapshotDate,
                'tickets_id' => $ticketId,
                'entities_id' => (int) $interval['entities_id'],
                'status' => (int) $interval['state_value'],
                'priority' => 0,
                'age_seconds' => max(0, $cutoffUtc->getTimestamp() - $createdAt->getTimestamp()),
                'is_open' => 1,
            ]);
            ++$processed;
        }

        $aggregates = $DB->request([
            'SELECT' => ['entities_id', new QueryExpression('COUNT(*) AS backlog_value'), new QueryExpression('AVG(`age_seconds`) AS average_age')],
            'FROM' => 'glpi_plugin_marifex_daily_snapshots',
            'WHERE' => ['snapshot_date' => $snapshotDate, 'is_open' => 1],
            'GROUPBY' => ['entities_id'],
        ]);
        foreach ($aggregates as $row) {
            $this->writeRollup($snapshotDate, (int) $row['entities_id'], 'historical_open_backlog', (float) $row['backlog_value'], (int) $row['backlog_value']);
            $this->writeRollup($snapshotDate, (int) $row['entities_id'], 'average_open_ticket_age', (float) $row['average_age'], (int) $row['backlog_value']);
        }

        if ($ticketIds !== []) {
            $groupCounts = [];
            foreach ($DB->request([
                'SELECT' => ['tickets_id', 'entities_id', 'state_value'],
                'FROM' => 'glpi_plugin_marifex_state_intervals',
                'WHERE' => [
                    'tickets_id' => $ticketIds,
                    'state_type' => 'group',
                    ['started_at' => ['<', $cutoff]],
                    ['OR' => [['ended_at' => null], ['ended_at' => ['>', $cutoff]]]],
                ],
            ]) as $groupInterval) {
                $key = (int) $groupInterval['entities_id'] . ':' . (int) $groupInterval['state_value'];
                $groupCounts[$key] = ($groupCounts[$key] ?? 0) + 1;
            }
            foreach ($groupCounts as $key => $count) {
                [$entityId, $groupId] = array_map('intval', explode(':', $key, 2));
                $this->writeRollup($snapshotDate, $entityId, 'historical_group_backlog', (float) $count, $count, 'group', (string) $groupId);
            }
        }

        (new TicketOperationsSnapshotBuilder())->run($localDay, $timezone);
        (new DomainSnapshotBuilder())->run($localDay, $timezone);

        return $processed;
    }

    private function writeRollup(string $date, int $entityId, string $metric, float $value, int $samples, string $dimensionKey = '', string $dimensionValue = ''): void
    {
        global $DB;
        $DB->insert('glpi_plugin_marifex_daily_rollups', [
            'rollup_date' => $date,
            'entities_id' => $entityId,
            'metric_key' => $metric,
            'dimension_key' => $dimensionKey,
            'dimension_value' => $dimensionValue,
            'metric_value' => $value,
            'sample_count' => $samples,
        ]);
    }
}
