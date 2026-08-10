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
        'prohibited_software_installations',
        'unlicensed_software_installations',
        'low_disk_capacity_computers',
        'computers_in_stock_over_30_days',
        'incidents_by_operating_system',
        'repeat_incident_computers',
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
        'incident_linked_computers',
        'repeat_incident_computers_90d',
        'repeat_incident_asset_rate',
        'licence_covered_titles',
        'licence_installed_titles',
        'licence_utilization_rate',
        'licence_coverage_gap_rate',
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
        $written += $this->snapshotSoftwareRisk($snapshotDate, $cutoffUtc);
        $written += $this->snapshotAssetIncidents($snapshotDate, $cutoffUtc);
        $written += $this->snapshotPhase5bAssetIncidents($snapshotDate, $cutoffUtc);
        $written += $this->snapshotLicences($snapshotDate, $cutoffUtc);
        $written += $this->snapshotItilDomain($snapshotDate, $startUtc, $cutoffUtc, 'glpi_changes', 'change');
        $written += $this->snapshotItilDomain($snapshotDate, $startUtc, $cutoffUtc, 'glpi_problems', 'problem');
        return $written;
    }

    private function snapshotAssets(string $date, DateTimeImmutable $cutoffUtc): int
    {
        global $DB;
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $staleBefore = $cutoffUtc->modify('-30 days')->format('Y-m-d H:i:s');
        $stockStateIds = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_states']) as $state) {
            $name = mb_strtolower((string) $state['completename']);
            if (str_contains($name, 'stock') || str_contains($name, 'store')) {
                $stockStateIds[(int) $state['id']] = true;
            }
        }
        $totals = [];
        $stale = [];
        $stock = [];
        $states = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id', 'states_id', 'last_inventory_update', 'date_creation'],
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
            if (!$computer['last_inventory_update']
                || (string) $computer['last_inventory_update'] >= $cutoff
                || (string) $computer['last_inventory_update'] < $staleBefore) {
                $stale[$entityId] = ($stale[$entityId] ?? 0) + 1;
            }
            if (isset($stockStateIds[$stateId]) && (string) $computer['date_creation'] < $staleBefore) {
                $stock[$entityId] = ($stock[$entityId] ?? 0) + 1;
            }
            $states[$entityId][$stateId] = ($states[$entityId][$stateId] ?? 0) + 1;
        }

        $lowDiskComputers = [];
        foreach ($DB->request([
            'SELECT' => ['entities_id', 'items_id', 'totalsize', 'freesize', 'date_creation'],
            'FROM' => 'glpi_items_disks',
            'WHERE' => ['itemtype' => 'Computer', 'is_deleted' => 0, ['date_creation' => ['<', $cutoff]]],
        ]) as $disk) {
            $total = (int) $disk['totalsize'];
            if ($total > 0 && ((int) $disk['freesize'] / $total) < 0.10) {
                $lowDiskComputers[(int) $disk['entities_id']][(int) $disk['items_id']] = true;
            }
        }

        $written = 0;
        foreach ($totals as $entityId => $value) {
            $this->writeRollup($date, $entityId, 'asset_inventory_total', $value, $value);
            $this->writeRollup($date, $entityId, 'stale_computer_inventory', $stale[$entityId] ?? 0, $value);
            $lowDisk = count($lowDiskComputers[$entityId] ?? []);
            $this->writeRollup($date, $entityId, 'low_disk_capacity_computers', $lowDisk, max(1, $value));
            $this->writeRollup($date, $entityId, 'computers_in_stock_over_30_days', $stock[$entityId] ?? 0, max(1, $value));
            $written += 4;
            foreach ($states[$entityId] ?? [] as $stateId => $count) {
                $this->writeRollup($date, $entityId, 'asset_inventory_by_state', $count, $count, 'state', (string) $stateId);
                ++$written;
            }
        }
        return $written;
    }

    private function snapshotSoftwareRisk(string $date, DateTimeImmutable $cutoffUtc): int
    {
        global $DB;
        $cutoffDate = $cutoffUtc->format('Y-m-d');
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $software = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'is_valid'],
            'FROM' => 'glpi_softwares',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0],
        ]) as $row) {
            $software[(int) $row['id']] = ['valid' => (int) $row['is_valid'] === 1];
        }
        $versions = [];
        foreach ($DB->request(['SELECT' => ['id', 'softwares_id'], 'FROM' => 'glpi_softwareversions']) as $version) {
            $versions[(int) $version['id']] = (int) $version['softwares_id'];
        }
        $installations = [];
        foreach ($DB->request([
            'SELECT' => ['entities_id', 'softwareversions_id', 'date_install'],
            'FROM' => 'glpi_items_softwareversions',
            'WHERE' => ['itemtype' => 'Computer', 'is_deleted' => 0, 'is_deleted_item' => 0, 'is_template_item' => 0],
        ]) as $installation) {
            if ($installation['date_install'] && (string) $installation['date_install'] >= $cutoffDate) {
                continue;
            }
            $softwareId = $versions[(int) $installation['softwareversions_id']] ?? 0;
            if ($softwareId > 0 && isset($software[$softwareId])) {
                $entityId = (int) $installation['entities_id'];
                $installations[$entityId][$softwareId] = ($installations[$entityId][$softwareId] ?? 0) + 1;
            }
        }
        $entitlements = [];
        foreach ($DB->request([
            'SELECT' => ['entities_id', 'softwares_id', 'number', 'date_creation'],
            'FROM' => 'glpi_softwarelicenses',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, 'is_valid' => 1],
        ]) as $licence) {
            if ($licence['date_creation'] && (string) $licence['date_creation'] >= $cutoff) {
                continue;
            }
            $entityId = (int) $licence['entities_id'];
            $softwareId = (int) $licence['softwares_id'];
            $entitlements[$entityId][$softwareId] = ($entitlements[$entityId][$softwareId] ?? 0) + max(0, (int) $licence['number']);
        }

        $written = 0;
        foreach ($installations as $entityId => $bySoftware) {
            foreach ($bySoftware as $softwareId => $count) {
                if (!$software[$softwareId]['valid']) {
                    $this->writeRollup($date, $entityId, 'prohibited_software_installations', $count, $count, 'software', (string) $softwareId);
                    ++$written;
                }
                $excess = max(0, $count - ($entitlements[$entityId][$softwareId] ?? 0));
                if ($excess > 0 && isset($entitlements[$entityId][$softwareId])) {
                    $this->writeRollup($date, $entityId, 'unlicensed_software_installations', $excess, $count, 'software', (string) $softwareId);
                    ++$written;
                }
            }
        }
        return $written;
    }

    private function snapshotAssetIncidents(string $date, DateTimeImmutable $cutoffUtc): int
    {
        global $DB;
        $from = $cutoffUtc->modify('-30 days')->format('Y-m-d H:i:s');
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $tickets = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0, 'type' => 1, ['date_creation' => ['>=', $from]], ['date_creation' => ['<', $cutoff]]],
        ]) as $ticket) {
            $tickets[(int) $ticket['id']] = true;
        }
        if ($tickets === []) {
            return 0;
        }
        $computers = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM' => 'glpi_computers',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0],
        ]) as $computer) {
            $computers[(int) $computer['id']] = (int) $computer['entities_id'];
        }
        $operatingSystems = [];
        foreach ($DB->request([
            'SELECT' => ['items_id', 'operatingsystems_id', 'install_date'],
            'FROM' => 'glpi_items_operatingsystems',
            'WHERE' => ['itemtype' => 'Computer', 'is_deleted' => 0],
        ]) as $operatingSystem) {
            if ($operatingSystem['install_date'] && (string) $operatingSystem['install_date'] >= $cutoffUtc->format('Y-m-d')) {
                continue;
            }
            $operatingSystems[(int) $operatingSystem['items_id']] = (int) $operatingSystem['operatingsystems_id'];
        }
        $incidentCounts = [];
        $osCounts = [];
        foreach ($DB->request([
            'SELECT' => ['items_id', 'tickets_id'],
            'FROM' => 'glpi_items_tickets',
            'WHERE' => ['itemtype' => 'Computer', 'tickets_id' => array_keys($tickets)],
        ]) as $link) {
            $computerId = (int) $link['items_id'];
            $entityId = $computers[$computerId] ?? null;
            if ($entityId === null) {
                continue;
            }
            $incidentCounts[$entityId][$computerId] = ($incidentCounts[$entityId][$computerId] ?? 0) + 1;
            $osId = $operatingSystems[$computerId] ?? 0;
            $osCounts[$entityId][$osId] = ($osCounts[$entityId][$osId] ?? 0) + 1;
        }
        $written = 0;
        foreach ($osCounts as $entityId => $counts) {
            foreach ($counts as $osId => $count) {
                $this->writeRollup($date, $entityId, 'incidents_by_operating_system', $count, $count, 'operating_system', (string) $osId);
                ++$written;
            }
        }
        foreach ($incidentCounts as $entityId => $counts) {
            foreach ($counts as $computerId => $count) {
                if ($count >= 2) {
                    $this->writeRollup($date, $entityId, 'repeat_incident_computers', $count, $count, 'computer', (string) $computerId);
                    ++$written;
                }
            }
        }
        return $written;
    }

    private function snapshotLicences(string $date, DateTimeImmutable $cutoffUtc): int
    {
        global $DB;
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $cutoffDate = $cutoffUtc->format('Y-m-d');
        $licences = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id', 'softwares_id', 'number', 'date_creation'],
            'FROM' => 'glpi_softwarelicenses',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, 'is_valid' => 1],
        ]) as $licence) {
            if ($licence['date_creation'] && (string) $licence['date_creation'] >= $cutoff) {
                continue;
            }
            $licences[(int) $licence['id']] = [
                'entity_id' => (int) $licence['entities_id'],
                'software_id' => (int) $licence['softwares_id'],
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
        $titles = [];
        foreach ($licences as $licence) {
            $entityId = $licence['entity_id'];
            $entities[$entityId]['entitlements'] = ($entities[$entityId]['entitlements'] ?? 0) + $licence['entitlements'];
            $entities[$entityId]['allocations'] = ($entities[$entityId]['allocations'] ?? 0) + $licence['allocations'];
            $entities[$entityId]['overallocated'] = ($entities[$entityId]['overallocated'] ?? 0)
                + max(0, $licence['allocations'] - $licence['entitlements']);
            $softwareId = $licence['software_id'];
            $titles[$entityId][$softwareId]['entitlements'] = ($titles[$entityId][$softwareId]['entitlements'] ?? 0) + $licence['entitlements'];
            $titles[$entityId][$softwareId]['allocations'] = ($titles[$entityId][$softwareId]['allocations'] ?? 0) + $licence['allocations'];
        }

        $versionSoftware = [];
        foreach ($DB->request(['SELECT' => ['id', 'softwares_id'], 'FROM' => 'glpi_softwareversions']) as $version) {
            $versionSoftware[(int) $version['id']] = (int) $version['softwares_id'];
        }
        $installedTitles = [];
        foreach ($DB->request([
            'SELECT' => ['entities_id', 'softwareversions_id', 'date_install'],
            'FROM' => 'glpi_items_softwareversions',
            'WHERE' => ['itemtype' => 'Computer', 'is_deleted' => 0, 'is_deleted_item' => 0, 'is_template_item' => 0],
        ]) as $installation) {
            if ($installation['date_install'] && (string) $installation['date_install'] >= $cutoffDate) {
                continue;
            }
            $softwareId = $versionSoftware[(int) $installation['softwareversions_id']] ?? 0;
            if ($softwareId > 0) {
                $installedTitles[(int) $installation['entities_id']][$softwareId] = true;
            }
        }

        $written = 0;
        $observationEntities = array_unique(array_merge(array_keys($titles), array_keys($installedTitles)));
        foreach ($observationEntities as $entityId) {
            $softwareIds = array_unique(array_merge(array_keys($titles[$entityId] ?? []), array_keys($installedTitles[$entityId] ?? [])));
            foreach ($softwareIds as $softwareId) {
                $DB->insert('glpi_plugin_marifex_daily_licence_title_observations', [
                    'snapshot_date' => $date,
                    'entities_id' => (int) $entityId,
                    'softwares_id' => (int) $softwareId,
                    'entitlement_count' => (int) ($titles[$entityId][$softwareId]['entitlements'] ?? 0),
                    'allocation_count' => (int) ($titles[$entityId][$softwareId]['allocations'] ?? 0),
                    'is_installed' => isset($installedTitles[$entityId][$softwareId]) ? 1 : 0,
                ]);
                ++$written;
            }
        }
        foreach ($entities as $entityId => $values) {
            $entitlements = (int) $values['entitlements'];
            $allocations = (int) $values['allocations'];
            $rate = $allocations > 0 ? min(100.0, ($entitlements / $allocations) * 100) : 0.0;
            $this->writeRollup($date, $entityId, 'software_license_entitlements', $entitlements, $entitlements);
            $this->writeRollup($date, $entityId, 'software_license_allocations', $allocations, $allocations);
            $this->writeRollup($date, $entityId, 'software_license_overallocated_seats', (int) $values['overallocated'], $allocations);
            $this->writeRollup($date, $entityId, 'software_license_compliance_rate', $rate, $allocations);
            $covered = array_filter($titles[$entityId] ?? [], static fn(array $title): bool => ($title['entitlements'] ?? 0) > 0 && ($title['allocations'] ?? 0) > 0);
            $coveredEntitlements = array_sum(array_column($covered, 'entitlements'));
            $coveredAllocations = array_sum(array_column($covered, 'allocations'));
            $installed = array_keys($installedTitles[$entityId] ?? []);
            $gap = count(array_filter($installed, static fn(int $softwareId): bool => (int) ($titles[$entityId][$softwareId]['entitlements'] ?? 0) <= 0));
            $this->writeRollup($date, $entityId, 'licence_covered_titles', count($covered), count($covered));
            $this->writeRollup($date, $entityId, 'licence_installed_titles', count($installed), count($installed));
            $this->writeRollup($date, $entityId, 'licence_utilization_rate', $coveredEntitlements > 0 ? $coveredAllocations / $coveredEntitlements * 100 : 0, $coveredEntitlements);
            $this->writeRollup($date, $entityId, 'licence_coverage_gap_rate', count($installed) > 0 ? $gap / count($installed) * 100 : 0, count($installed));
            $written += 8;
        }
        foreach (array_diff(array_keys($installedTitles), array_keys($entities)) as $entityId) {
            $installed = array_keys($installedTitles[$entityId]);
            $this->writeRollup($date, (int) $entityId, 'licence_covered_titles', 0, 0);
            $this->writeRollup($date, (int) $entityId, 'licence_installed_titles', count($installed), count($installed));
            $this->writeRollup($date, (int) $entityId, 'licence_utilization_rate', 0, 0);
            $this->writeRollup($date, (int) $entityId, 'licence_coverage_gap_rate', 100, count($installed));
            $written += 4;
        }
        return $written;
    }

    private function snapshotPhase5bAssetIncidents(string $date, DateTimeImmutable $cutoffUtc): int
    {
        global $DB;
        $from = $cutoffUtc->modify('-90 days')->format('Y-m-d H:i:s');
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $tickets = [];
        foreach ($DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0, 'type' => 1, ['date_creation' => ['>=', $from]], ['date_creation' => ['<', $cutoff]]],
        ]) as $ticket) {
            $tickets[(int) $ticket['id']] = true;
        }
        $computerEntities = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_computers',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0],
        ]) as $computer) {
            $computerEntities[(int) $computer['id']] = (int) $computer['entities_id'];
        }
        $counts = [];
        if ($tickets !== []) {
            foreach ($DB->request([
                'SELECT' => ['items_id', 'tickets_id'], 'FROM' => 'glpi_items_tickets',
                'WHERE' => ['itemtype' => 'Computer', 'tickets_id' => array_keys($tickets)],
            ]) as $link) {
                $computerId = (int) $link['items_id'];
                $entityId = $computerEntities[$computerId] ?? null;
                if ($entityId !== null) {
                    $counts[$entityId][$computerId][(int) $link['tickets_id']] = true;
                }
            }
        }
        $entityIds = array_values(array_unique(array_values($computerEntities)));
        $written = 0;
        foreach ($entityIds as $entityId) {
            $linked = count($counts[$entityId] ?? []);
            $repeat = count(array_filter($counts[$entityId] ?? [], static fn(array $ticketSet): bool => count($ticketSet) >= 2));
            $this->writeRollup($date, $entityId, 'incident_linked_computers', $linked, $linked);
            $this->writeRollup($date, $entityId, 'repeat_incident_computers_90d', $repeat, $linked);
            $this->writeRollup($date, $entityId, 'repeat_incident_asset_rate', $linked > 0 ? $repeat / $linked * 100 : 0, $linked);
            $written += 3;
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
