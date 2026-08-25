<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Insight;

final class InsightDomainRegistry
{
    /** @var array<string, string> */
    private const METRIC_DOMAINS = [
        'asset_inventory_total' => 'asset', 'asset_inventory_by_state' => 'asset',
        'stale_computer_inventory' => 'asset', 'low_disk_capacity_computers' => 'asset',
        'computers_in_stock_over_30_days' => 'asset', 'incidents_by_operating_system' => 'asset',
        'repeat_incident_computers' => 'asset', 'incident_linked_computers' => 'asset',
        'repeat_incident_computers_90d' => 'asset', 'repeat_incident_asset_rate' => 'asset',
        'prohibited_software_installations' => 'licence', 'unlicensed_software_installations' => 'licence',
        'software_license_entitlements' => 'licence', 'software_license_allocations' => 'licence',
        'software_license_overallocated_seats' => 'licence', 'software_license_compliance_rate' => 'licence',
        'licence_covered_titles' => 'licence', 'licence_installed_titles' => 'licence',
        'licence_utilization_rate' => 'licence', 'licence_coverage_gap_rate' => 'licence',
        'open_changes' => 'change', 'daily_change_volume' => 'change',
        'daily_change_resolutions' => 'change', 'open_change_status_distribution' => 'change',
        'open_problems' => 'problem', 'daily_problem_volume' => 'problem',
        'daily_problem_resolutions' => 'problem', 'open_problem_status_distribution' => 'problem',
    ];

    /**
     * An empty list is the controlled Executive context used by the screen API.
     *
     * @param list<array<string, mixed>> $widgets
     * @return list<string>
     */
    public static function forWidgets(array $widgets): array
    {
        $domains = [];
        foreach ($widgets as $widget) {
            $metric = (string) ($widget['metric'] ?? '');
            $domains[self::METRIC_DOMAINS[$metric] ?? 'ticket'] = true;
        }
        if (isset($domains['ticket']) || count($domains) >= 3) {
            return [];
        }
        return array_keys($domains);
    }
}
