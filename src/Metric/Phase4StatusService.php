<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Metric;

use DateTimeImmutable;
use DateTimeZone;
use GlpiPlugin\Marifex\Security\EntityScope;
use QueryExpression;

final class Phase4StatusService
{
    private const DOMAINS = [
        'Service desk' => ['open_tickets_by_priority', 'unassigned_open_tickets', 'average_unassigned_time', 'tickets_approaching_sla_breach', 'sla_breach_count', 'sla_breach_rate', 'sla_breaches_by_technician', 'tickets_by_request_source', 'created_vs_resolved_tickets', 'assignment_changes_per_ticket', 'technician_workload_distribution', 'unsatisfied_survey_responses', 'resolution_time_age_bands', 'open_incidents_by_assignment_group', 'created_tickets_by_request_source', 'ticket_reopen_events', 'ticket_resolution_events', 'first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds', 'survey_responses_total', 'dissatisfied_responses_total', 'customer_dissatisfaction_rate', 'solution_proposed_tickets', 'solution_refused_tickets', 'refused_solution_rate'],
        'Assets' => ['asset_inventory_total', 'asset_inventory_by_state', 'stale_computer_inventory', 'low_disk_capacity_computers', 'computers_in_stock_over_30_days', 'incidents_by_operating_system', 'repeat_incident_computers', 'incident_linked_computers', 'repeat_incident_computers_90d', 'repeat_incident_asset_rate'],
        'Licences' => ['software_license_entitlements', 'software_license_allocations', 'software_license_overallocated_seats', 'software_license_compliance_rate', 'prohibited_software_installations', 'unlicensed_software_installations', 'licence_covered_titles', 'licence_installed_titles', 'licence_utilization_rate', 'licence_coverage_gap_rate'],
        'Changes' => ['open_changes', 'daily_change_volume', 'daily_change_resolutions', 'open_change_status_distribution'],
        'Problems' => ['open_problems', 'daily_problem_volume', 'daily_problem_resolutions', 'open_problem_status_distribution'],
    ];
    private const CURRENT_PRODUCTS = ['latest_solution_refused_tickets', 'active_sla_exceptions', 'operational_attention'];

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
            $domainRow = $DB->request([
                'SELECT' => [new QueryExpression('MAX(`rollup_date`) AS latest_date')],
                'FROM' => 'glpi_plugin_marifex_daily_rollups',
                'WHERE' => $this->entityScope->criteria() + ['metric_key' => $keys],
            ])->current();
            $domainLatest = $domainRow && $domainRow['latest_date'] ? (string) $domainRow['latest_date'] : null;
            foreach ($keys as $key) {
                $row = $DB->request([
                    'SELECT' => [
                        new QueryExpression('MAX(`rollup_date`) AS latest_date'),
                        new QueryExpression('COUNT(*) AS grain_count'),
                    ],
                    'FROM' => 'glpi_plugin_marifex_daily_rollups',
                    'WHERE' => $this->entityScope->criteria() + ['metric_key' => $key],
                ])->current();
                // Dimension metrics legitimately have no row when a completed
                // domain snapshot finds zero matching items. In that case the
                // domain's snapshot date proves the collector ran successfully.
                $latest = $row && $row['latest_date'] ? (string) $row['latest_date'] : $domainLatest;
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
        $matrixRow = $DB->request([
            'SELECT' => [new QueryExpression('MAX(`rollup_date`) AS latest_date'), new QueryExpression('COUNT(*) AS grain_count')],
            'FROM' => 'glpi_plugin_marifex_daily_matrix_rollups',
            'WHERE' => $this->entityScope->criteria() + ['metric_key' => 'open_tickets_priority_category_matrix'],
        ])->current();
        $matrixLatest = $matrixRow && $matrixRow['latest_date'] ? (string) $matrixRow['latest_date'] : null;
        $statuses[] = [
            'domain' => 'Service desk', 'metric' => 'open_tickets_priority_category_matrix',
            'label' => $this->registry->get('open_tickets_priority_category_matrix')->label,
            'latest_date' => $matrixLatest, 'grain_count' => (int) ($matrixRow['grain_count'] ?? 0),
            'status' => $matrixLatest === null ? 'missing' : ($matrixLatest >= $expectedDate ? 'current' : 'stale'),
        ];
        foreach (self::CURRENT_PRODUCTS as $key) {
            $statuses[] = [
                'domain' => 'Current governed data', 'metric' => $key, 'label' => $this->registry->get($key)->label,
                'latest_date' => gmdate('Y-m-d'), 'grain_count' => 0, 'status' => 'live',
            ];
        }
        return $statuses;
    }
}
