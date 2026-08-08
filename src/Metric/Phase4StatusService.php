<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Metric;

use DateTimeImmutable;
use DateTimeZone;
use GlpiPlugin\Marifex\Security\EntityScope;
use QueryExpression;

final class Phase4StatusService
{
    private const DOMAINS = [
        'Assets' => ['asset_inventory_total', 'asset_inventory_by_state', 'stale_computer_inventory'],
        'Licences' => ['software_license_entitlements', 'software_license_allocations', 'software_license_overallocated_seats', 'software_license_compliance_rate'],
        'Changes' => ['open_changes', 'daily_change_volume', 'daily_change_resolutions', 'open_change_status_distribution'],
        'Problems' => ['open_problems', 'daily_problem_volume', 'daily_problem_resolutions', 'open_problem_status_distribution'],
    ];

    public function __construct(
        private readonly EntityScope $entityScope = new EntityScope(),
        private readonly MetricRegistry $registry = new MetricRegistry(),
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_daily_rollups')) {
            return [];
        }

        $expectedDate = (new DateTimeImmutable('yesterday', new DateTimeZone('UTC')))->format('Y-m-d');
        $statuses = [];
        foreach (self::DOMAINS as $domain => $keys) {
            foreach ($keys as $key) {
                $row = $DB->request([
                    'SELECT' => [
                        new QueryExpression('MAX(`rollup_date`) AS latest_date'),
                        new QueryExpression('COUNT(*) AS grain_count'),
                    ],
                    'FROM' => 'glpi_plugin_marifex_daily_rollups',
                    'WHERE' => $this->entityScope->criteria() + ['metric_key' => $key],
                ])->current();
                $latest = $row && $row['latest_date'] ? (string) $row['latest_date'] : null;
                $statuses[] = [
                    'domain' => $domain,
                    'metric' => $key,
                    'label' => $this->registry->get($key)->label,
                    'latest_date' => $latest,
                    'grain_count' => (int) ($row['grain_count'] ?? 0),
                    'status' => $latest === null ? 'missing' : ($latest >= $expectedDate ? 'current' : 'stale'),
                ];
            }
        }
        return $statuses;
    }
}
