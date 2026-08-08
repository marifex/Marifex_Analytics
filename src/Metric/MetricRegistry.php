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
            'open_tickets_by_priority' => new MetricDefinition('open_tickets_by_priority', 'Open tickets by priority', 'data_mart', 'dimension_series'),
            'unassigned_open_tickets' => new MetricDefinition('unassigned_open_tickets', 'Unassigned open tickets', 'data_mart', 'integer_series'),
            'average_unassigned_time' => new MetricDefinition('average_unassigned_time', 'Average current unassigned age', 'data_mart', 'duration_series'),
            'tickets_approaching_sla_breach' => new MetricDefinition('tickets_approaching_sla_breach', 'Tickets approaching SLA breach', 'data_mart', 'integer_series'),
            'sla_breach_count' => new MetricDefinition('sla_breach_count', 'Open SLA breaches', 'data_mart', 'integer_series'),
            'sla_breach_rate' => new MetricDefinition('sla_breach_rate', 'Open SLA breach rate', 'data_mart', 'percentage_series'),
            'sla_breaches_by_technician' => new MetricDefinition('sla_breaches_by_technician', 'SLA breaches by technician', 'data_mart', 'dimension_series'),
            'tickets_by_request_source' => new MetricDefinition('tickets_by_request_source', 'Open tickets by request source', 'data_mart', 'dimension_series'),
            'created_vs_resolved_tickets' => new MetricDefinition('created_vs_resolved_tickets', 'Created versus resolved tickets', 'data_mart', 'dimension_series'),
            'assignment_changes_per_ticket' => new MetricDefinition('assignment_changes_per_ticket', 'Technician assignment changes per active ticket', 'data_mart', 'decimal_series'),
            'technician_workload_distribution' => new MetricDefinition('technician_workload_distribution', 'Technician workload distribution', 'data_mart', 'dimension_series'),
            'unsatisfied_survey_responses' => new MetricDefinition('unsatisfied_survey_responses', 'Unsatisfied survey responses', 'data_mart', 'integer_series'),
            'resolution_time_age_bands' => new MetricDefinition('resolution_time_age_bands', 'Resolution-time age bands', 'data_mart', 'dimension_series'),
            'asset_inventory_total' => new MetricDefinition('asset_inventory_total', 'Managed computers', 'data_mart', 'integer_series'),
            'asset_inventory_by_state' => new MetricDefinition('asset_inventory_by_state', 'Computer inventory by lifecycle state', 'data_mart', 'dimension_series'),
            'stale_computer_inventory' => new MetricDefinition('stale_computer_inventory', 'Computers without inventory in 30 days', 'data_mart', 'integer_series'),
            'prohibited_software_installations' => new MetricDefinition('prohibited_software_installations', 'Installed software marked invalid', 'data_mart', 'dimension_series'),
            'unlicensed_software_installations' => new MetricDefinition('unlicensed_software_installations', 'Software installations above entitlement', 'data_mart', 'dimension_series'),
            'low_disk_capacity_computers' => new MetricDefinition('low_disk_capacity_computers', 'Computers below 10 percent free disk capacity', 'data_mart', 'integer_series'),
            'computers_in_stock_over_30_days' => new MetricDefinition('computers_in_stock_over_30_days', 'Computers in stock over 30 days', 'data_mart', 'integer_series'),
            'incidents_by_operating_system' => new MetricDefinition('incidents_by_operating_system', 'Computer incidents by operating system', 'data_mart', 'dimension_series'),
            'repeat_incident_computers' => new MetricDefinition('repeat_incident_computers', 'Computers with repeated incidents', 'data_mart', 'dimension_series'),
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

