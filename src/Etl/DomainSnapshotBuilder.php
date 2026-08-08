<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use DateTimeImmutable;
use DateTimeZone;
use Glpi\DBAL\QueryExpression;

final class DomainSnapshotBuilder
{
    private const METRICS = [
        'asset_inventory_total',
        'asset_inventory_by_state',
        'stale_computer_inventory',
        'software_license_entitlements',
        'software_license_allocations',
        'software_license_overallocated_seats',
        'software_license_compliance_rate',
        'open_changes',
        'daily_change_volume',
        'daily_change_resolutions',
        'open_change_status_distribution',
        'open_problems',
        'daily_problem_volume',
        'daily_problem_resolutions',
        'open_problem_status_distribution',
    ];

    public function run(DateTimeImmutable $localDay, DateTimeZone $timezone): int
    {
        global $DB;
        $snapshotDate = $localDay->format('Y-m-d');
        $startUtc = $localDay->setTimezone(new DateTimeZone('UTC'));
        $cutoffUtc = $localDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));

        $DB->delete('glpi_plugin_marifex_daily_rollups', [
            'rollup_date' => $snapshotDate,
            'metric_key' => self::METRICS,
        ]);

        $written = $this->snapshotAssets($snapshotDate, $cutoffUtc);
        $written += $this->snapshotLicences($snapshotDate);
        $written += $this->snapshotItilDomain($snapshotDate, $startUtc, $cutoffUtc, 'glpi_changes', 'change');
        $written += $this->snapshotItilDomain($snapshotDate, $startUtc, $cutoffUtc, 'glpi_problems', 'problem');
        return $written;
    }

    private function snapshotAssets(string $date, DateTimeImmutable $cutoffUtc): int
    {
        global $DB;
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $staleBefore = $cutoffUtc->modify('-30 days')->format('Y-m-d H:i:s');
        $totals = [];
        $stale = [];
        $states = [];
        foreach ($DB->request([
            'SELECT' => ['entities_id', 'states_id', 'last_inventory_update'],
            'FROM' => 'glpi_computers',
            'WHERE' => [
                'is_deleted' => 0,
                'is_template' => 0,
                ['date_creation' => ['<', $cutoff]],
            ],
        ]) as $computer) {
            $entityId = (int) $computer['entities_id'];
            $stateId = (int) $computer['states_id'];
            $totals[$entityId] = ($totals[$entityId] ?? 0) + 1;
            if (!$computer['last_inventory_update'] || (string) $computer['last_inventory_update'] < $staleBefore) {
                $stale[$entityId] = ($stale[$entityId] ?? 0) + 1;
            }
            $states[$entityId][$stateId] = ($states[$entityId][$stateId] ?? 0) + 1;
        }

        $written = 0;
        foreach ($totals as $entityId => $value) {
            $this->writeRollup($date, $entityId, 'asset_inventory_total', $value, $value);
            $this->writeRollup($date, $entityId, 'stale_computer_inventory', $stale[$entityId] ?? 0, $value);
            $written += 2;
            foreach ($states[$entityId] ?? [] as $stateId => $count) {
                $this->writeRollup($date, $entityId, 'asset_inventory_by_state', $count, $count, 'state', (string) $stateId);
                ++$written;
            }
        }
        return $written;
    }

    private function snapshotLicences(string $date): int
    {
        global $DB;
        $licences = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id', 'number'],
            'FROM' => 'glpi_softwarelicenses',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, 'is_valid' => 1],
        ]) as $licence) {
            $licences[(int) $licence['id']] = [
                'entity_id' => (int) $licence['entities_id'],
                'entitlements' => max(0, (int) $licence['number']),
                'allocations' => 0,
            ];
        }
        if ($licences !== []) {
            foreach ($DB->request([
                'SELECT' => ['softwarelicenses_id', new QueryExpression('COUNT(*) AS allocation_count')],
                'FROM' => 'glpi_items_softwarelicenses',
                'WHERE' => ['is_deleted' => 0, 'softwarelicenses_id' => array_keys($licences)],
                'GROUPBY' => ['softwarelicenses_id'],
            ]) as $allocation) {
                $licenceId = (int) $allocation['softwarelicenses_id'];
                if (isset($licences[$licenceId])) {
                    $licences[$licenceId]['allocations'] = (int) $allocation['allocation_count'];
                }
            }
        }

        $entities = [];
        foreach ($licences as $licence) {
            $entityId = $licence['entity_id'];
            $entities[$entityId]['entitlements'] = ($entities[$entityId]['entitlements'] ?? 0) + $licence['entitlements'];
            $entities[$entityId]['allocations'] = ($entities[$entityId]['allocations'] ?? 0) + $licence['allocations'];
            $entities[$entityId]['overallocated'] = ($entities[$entityId]['overallocated'] ?? 0)
                + max(0, $licence['allocations'] - $licence['entitlements']);
        }

        $written = 0;
        foreach ($entities as $entityId => $values) {
            $entitlements = (int) $values['entitlements'];
            $allocations = (int) $values['allocations'];
            $rate = $allocations === 0 ? 100.0 : min(100.0, ($entitlements / $allocations) * 100);
            $this->writeRollup($date, $entityId, 'software_license_entitlements', $entitlements, $entitlements);
            $this->writeRollup($date, $entityId, 'software_license_allocations', $allocations, $allocations);
            $this->writeRollup($date, $entityId, 'software_license_overallocated_seats', (int) $values['overallocated'], $allocations);
            $this->writeRollup($date, $entityId, 'software_license_compliance_rate', $rate, max(1, $allocations));
            $written += 4;
        }
        return $written;
    }

    private function snapshotItilDomain(
        string $date,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $cutoffUtc,
        string $table,
        string $domain,
    ): int {
        global $DB;
        $start = $startUtc->format('Y-m-d H:i:s');
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $entities = [];
        foreach ($DB->request([
            'SELECT' => ['entities_id', 'status', 'date', 'date_creation', 'solvedate'],
            'FROM' => $table,
            'WHERE' => ['is_deleted' => 0, ['date_creation' => ['<', $cutoff]]],
        ]) as $item) {
            $entityId = (int) $item['entities_id'];
            $createdAt = (string) ($item['date'] ?: $item['date_creation']);
            $solvedAt = $item['solvedate'] ? (string) $item['solvedate'] : null;
            if ($createdAt >= $start && $createdAt < $cutoff) {
                $entities[$entityId]['volume'] = ($entities[$entityId]['volume'] ?? 0) + 1;
            }
            if ($solvedAt !== null && $solvedAt >= $start && $solvedAt < $cutoff) {
                $entities[$entityId]['resolutions'] = ($entities[$entityId]['resolutions'] ?? 0) + 1;
            }
            if ($createdAt < $cutoff && ($solvedAt === null || $solvedAt >= $cutoff)) {
                $status = (int) $item['status'];
                $entities[$entityId]['open'] = ($entities[$entityId]['open'] ?? 0) + 1;
                $entities[$entityId]['statuses'][$status] = ($entities[$entityId]['statuses'][$status] ?? 0) + 1;
            }
        }

        $written = 0;
        foreach ($entities as $entityId => $values) {
            $this->writeRollup($date, $entityId, 'open_' . $domain . 's', (int) ($values['open'] ?? 0), (int) ($values['open'] ?? 0));
            $this->writeRollup($date, $entityId, 'daily_' . $domain . '_volume', (int) ($values['volume'] ?? 0), (int) ($values['volume'] ?? 0));
            $this->writeRollup($date, $entityId, 'daily_' . $domain . '_resolutions', (int) ($values['resolutions'] ?? 0), (int) ($values['resolutions'] ?? 0));
            $written += 3;
            foreach ($values['statuses'] ?? [] as $status => $count) {
                $this->writeRollup($date, $entityId, 'open_' . $domain . '_status_distribution', $count, $count, 'status', (string) $status);
                ++$written;
            }
        }
        return $written;
    }

    private function writeRollup(
        string $date,
        int $entityId,
        string $metric,
        float|int $value,
        int $samples,
        string $dimensionKey = '',
        string $dimensionValue = '',
    ): void {
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
