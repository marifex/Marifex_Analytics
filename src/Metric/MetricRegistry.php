<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Metric;

use InvalidArgumentException;

final class MetricRegistry
{
    /** @var array<string, MetricDefinition> */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            'current_open_tickets' => new MetricDefinition(
                'current_open_tickets',
                'Current open tickets',
                'live',
                'integer'
            ),
            'historical_open_backlog' => new MetricDefinition(
                'historical_open_backlog',
                'Historical open backlog',
                'data_mart',
                'time_series'
            ),
            'average_open_ticket_age' => new MetricDefinition(
                'average_open_ticket_age',
                'Average open ticket age',
                'data_mart',
                'duration_series'
            ),
            'historical_group_backlog' => new MetricDefinition(
                'historical_group_backlog',
                'Historical backlog by assigned group',
                'data_mart',
                'dimension_series'
            ),
            'asset_inventory_total' => new MetricDefinition('asset_inventory_total', 'Managed computers', 'data_mart', 'integer_series'),
            'asset_inventory_by_state' => new MetricDefinition('asset_inventory_by_state', 'Computer inventory by lifecycle state', 'data_mart', 'dimension_series'),
            'stale_computer_inventory' => new MetricDefinition('stale_computer_inventory', 'Computers without inventory in 30 days', 'data_mart', 'integer_series'),
            'software_license_entitlements' => new MetricDefinition('software_license_entitlements', 'Software licence entitlements', 'data_mart', 'integer_series'),
            'software_license_allocations' => new MetricDefinition('software_license_allocations', 'Allocated software licence seats', 'data_mart', 'integer_series'),
            'software_license_overallocated_seats' => new MetricDefinition('software_license_overallocated_seats', 'Overallocated software licence seats', 'data_mart', 'integer_series'),
            'software_license_compliance_rate' => new MetricDefinition('software_license_compliance_rate', 'Software licence compliance rate', 'data_mart', 'percentage_series'),
            'open_changes' => new MetricDefinition('open_changes', 'Open changes', 'data_mart', 'integer_series'),
            'daily_change_volume' => new MetricDefinition('daily_change_volume', 'Daily change volume', 'data_mart', 'integer_series'),
            'daily_change_resolutions' => new MetricDefinition('daily_change_resolutions', 'Daily resolved changes', 'data_mart', 'integer_series'),
            'open_change_status_distribution' => new MetricDefinition('open_change_status_distribution', 'Open changes by status', 'data_mart', 'dimension_series'),
            'open_problems' => new MetricDefinition('open_problems', 'Open problems', 'data_mart', 'integer_series'),
            'daily_problem_volume' => new MetricDefinition('daily_problem_volume', 'Daily problem volume', 'data_mart', 'integer_series'),
            'daily_problem_resolutions' => new MetricDefinition('daily_problem_resolutions', 'Daily resolved problems', 'data_mart', 'integer_series'),
            'open_problem_status_distribution' => new MetricDefinition('open_problem_status_distribution', 'Open problems by status', 'data_mart', 'dimension_series'),
        ];
    }

    public function get(string $key): MetricDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException('Unknown metric.');
    }

    /** @return list<MetricDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}

