<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use RuntimeException;

final class CsvReportRenderer
{
    /** @var list<string> */
    private const COLUMNS = [
        'record_type', 'section', 'metric', 'current', 'previous', 'movement', 'direction',
        'interpretation', 'period', 'data_status', 'evidence', 'dashboard', 'widget',
        'metric_or_insight_key', 'date', 'dimension', 'value', 'unit', 'comparison_basis',
        'activation_state', 'source_as_of', 'provenance', 'effective_provenance',
        'formula_version', 'formula', 'suppression_code', 'suppression_message',
        'materiality_outcome', 'coverage', 'entity_scope',
    ];

    /** @param array<string, mixed> $report */
    public function render(array $report, string $path): void
    {
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create the CSV report.');
        }

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, self::COLUMNS);

            $scope = json_encode($report['insights']['scope'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $readinessByMetric = array_column($report['insights']['readiness']['metrics'] ?? [], null, 'metric');
            $dashboard = (string) ($report['dashboard']['name'] ?? 'Analytics dashboard');
            $period = $this->period($report);
            $scopeLabel = (string) ($report['scope_label'] ?? ('Entity #' . (int) ($report['entities_id'] ?? 0)));

            foreach ($report['insights']['insights'] ?? [] as $insight) {
                $calculation = $insight['calculation'] ?? [];
                $unit = (string) ($insight['unit'] ?? '');
                $contributor = $insight['contributor']['label'] ?? $insight['contributor']['dimension'] ?? null;
                $this->row($stream, [
                    'record_type' => 'insight',
                    'section' => 'Executive insight brief',
                    'metric' => (string) ($insight['label'] ?? $this->label((string) $insight['key'])),
                    'current' => $this->formatAnalyticalValue($insight['current'] ?? null, $unit),
                    'previous' => $this->formatAnalyticalValue($insight['previous'] ?? null, $unit),
                    'movement' => $this->movement($insight, $unit),
                    'direction' => $this->direction((string) ($insight['direction'] ?? 'neutral')),
                    'interpretation' => (string) ($insight['narrative'] ?? ''),
                    'period' => (string) ($insight['comparison_basis'] ?? $period),
                    'data_status' => $this->activationLabel((string) ($insight['activation_state'] ?? '')),
                    'evidence' => $contributor === null ? 'Certified calculation evidence' : 'Largest contributor: ' . $contributor,
                    'dashboard' => $dashboard,
                    'metric_or_insight_key' => (string) $insight['key'],
                    'date' => (string) ($report['insights']['cutoff'] ?? $report['to']),
                    'current_value' => '',
                    'unit' => $unit,
                    'comparison_basis' => (string) ($insight['comparison_basis'] ?? ''),
                    'activation_state' => (string) ($insight['activation_state'] ?? ''),
                    'source_as_of' => (string) ($insight['as_of'] ?? ''),
                    'provenance' => (string) ($insight['provenance'] ?? ''),
                    'effective_provenance' => (string) ($insight['effective_provenance'] ?? ''),
                    'formula_version' => (string) ($calculation['formula_version'] ?? $report['insights']['formula_version'] ?? ''),
                    'formula' => (string) ($calculation['formula'] ?? ''),
                    'materiality_outcome' => (string) ($calculation['materiality_outcome'] ?? ''),
                    'coverage' => $this->json($calculation['coverage'] ?? []),
                    'entity_scope' => $scope,
                ]);
            }

            foreach ($report['insights']['observed_movements'] ?? [] as $movement) {
                $change = (float) ($movement['absolute_change'] ?? 0);
                $this->row($stream, [
                    'record_type' => 'derived_measure',
                    'section' => 'Monitoring context',
                    'metric' => (string) ($movement['label'] ?? $this->label((string) $movement['metric'])),
                    'current' => $this->formatAnalyticalValue($movement['current'] ?? null, ''),
                    'previous' => $this->formatAnalyticalValue($movement['baseline'] ?? null, ''),
                    'movement' => ($change >= 0 ? '+' : '') . $this->formatNumber($change),
                    'direction' => $change > 0 ? 'Increase' : ($change < 0 ? 'Decrease' : 'No change'),
                    'interpretation' => sprintf(
                        '%s by %s since monitoring began. This is monitoring context, not a prior-period finding.',
                        $change >= 0 ? 'Increased' : 'Decreased',
                        $this->formatNumber(abs($change)),
                    ),
                    'period' => (string) ($movement['comparison_basis'] ?? 'Since monitoring began'),
                    'data_status' => $this->activationLabel((string) ($movement['activation_state'] ?? 'OBSERVED_MOVEMENT')),
                    'evidence' => 'Monitoring baseline ' . (string) ($movement['monitoring_baseline_at'] ?? ''),
                    'dashboard' => $dashboard,
                    'metric_or_insight_key' => (string) $movement['metric'],
                    'date' => (string) ($report['insights']['cutoff'] ?? $report['to']),
                    'comparison_basis' => (string) ($movement['comparison_basis'] ?? ''),
                    'activation_state' => (string) ($movement['activation_state'] ?? ''),
                    'provenance' => (string) ($movement['provenance'] ?? ''),
                    'effective_provenance' => (string) ($movement['effective_provenance'] ?? ''),
                    'formula_version' => (string) ($report['insights']['formula_version'] ?? ''),
                    'formula' => 'Latest certified observation - stable monitoring baseline',
                    'materiality_outcome' => 'Not eligible for materiality or Executive insight selection',
                    'entity_scope' => $scope,
                ]);
            }

            foreach ($report['widgets'] as $item) {
                $widget = $item['definition'];
                $data = $item['data'];
                $readinessMetric = $widget['metric'] === 'current_open_tickets' ? 'historical_open_backlog' : $widget['metric'];
                $activation = $readinessByMetric[$readinessMetric] ?? [];
                $coverage = $this->coverage($activation);
                $summary = $this->widgetSummary($widget, $data);
                $section = $this->section($widget);

                $this->row($stream, [
                    'record_type' => 'metric',
                    'section' => $section,
                    'metric' => (string) $widget['title'],
                    'current' => $summary['display'],
                    'interpretation' => $summary['interpretation'],
                    'period' => (string) ($activation['comparison_basis'] ?? $period),
                    'data_status' => $this->dataStatus($data, $activation),
                    'evidence' => $summary['evidence'] . '; scope: ' . $scopeLabel,
                    'dashboard' => $dashboard,
                    'widget' => (string) $widget['title'],
                    'metric_or_insight_key' => (string) $widget['metric'],
                    'date' => (string) ($summary['date'] ?? $report['to']),
                    'dimension' => (string) ($summary['dimension'] ?? ''),
                    'value' => $summary['raw'],
                    'unit' => $this->metricUnit((string) $widget['metric']),
                    'comparison_basis' => (string) ($activation['comparison_basis'] ?? 'Current value'),
                    'activation_state' => (string) ($activation['activation_state'] ?? 'CURRENT_STATE'),
                    'source_as_of' => $this->sourceAsOf($data),
                    'provenance' => (string) ($data['provenance'] ?? ''),
                    'effective_provenance' => (string) ($data['effective_provenance'] ?? ''),
                    'coverage' => $coverage,
                    'entity_scope' => $scope,
                ]);
            }

            foreach ($report['insights']['suppressed'] ?? [] as $suppressed) {
                $this->row($stream, [
                    'record_type' => 'derived_measure',
                    'section' => 'Analytical status',
                    'metric' => $this->label((string) $suppressed['key']),
                    'current' => $this->formatAnalyticalValue($suppressed['current'] ?? null, (string) ($suppressed['unit'] ?? '')),
                    'previous' => $this->formatAnalyticalValue($suppressed['previous'] ?? null, (string) ($suppressed['unit'] ?? '')),
                    'movement' => $this->movement($suppressed, (string) ($suppressed['unit'] ?? '')),
                    'direction' => 'Not classified',
                    'interpretation' => (string) ($suppressed['message'] ?? 'The governed calculation is not available for this report.'),
                    'period' => (string) ($suppressed['comparison_basis'] ?? $period),
                    'data_status' => 'Not available for comparison',
                    'evidence' => 'Suppression: ' . (string) ($suppressed['code'] ?? 'UNAVAILABLE'),
                    'dashboard' => $dashboard,
                    'metric_or_insight_key' => (string) $suppressed['key'],
                    'date' => (string) ($report['insights']['cutoff'] ?? $report['to']),
                    'unit' => (string) ($suppressed['unit'] ?? ''),
                    'comparison_basis' => (string) ($suppressed['comparison_basis'] ?? ''),
                    'activation_state' => (string) ($suppressed['activation_state'] ?? ''),
                    'source_as_of' => (string) ($suppressed['last_refresh'] ?? ''),
                    'provenance' => (string) ($suppressed['provenance'] ?? ''),
                    'effective_provenance' => (string) ($suppressed['effective_provenance'] ?? ''),
                    'formula_version' => (string) ($suppressed['formula_version'] ?? $report['insights']['formula_version'] ?? ''),
                    'formula' => (string) ($suppressed['formula'] ?? ''),
                    'suppression_code' => (string) ($suppressed['code'] ?? ''),
                    'suppression_message' => (string) ($suppressed['message'] ?? ''),
                    'materiality_outcome' => (string) ($suppressed['materiality_outcome'] ?? 'suppressed'),
                    'coverage' => $this->json($suppressed['coverage'] ?? []),
                    'entity_scope' => $scope,
                ]);
            }

            foreach ($report['widgets'] as $item) {
                $this->detailRows($stream, $report, $item, $readinessByMetric, $scope);
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream
     *  @param array<string, mixed> $values
     */
    private function row($stream, array $values): void
    {
        $ordered = [];
        foreach (self::COLUMNS as $column) {
            $value = $values[$column] ?? '';
            $text = is_scalar($value) || $value === null ? (string) $value : $this->json($value);
            $ordered[] = preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
        }
        fputcsv($stream, $ordered);
    }

    /** @param array<string, mixed> $report
     *  @param array<string, mixed> $item
     *  @param array<string, array<string, mixed>> $readinessByMetric
     *  @param string $scope
     */
    private function detailRows($stream, array $report, array $item, array $readinessByMetric, string $scope): void
    {
        $widget = $item['definition'];
        $data = $item['data'];
        $metric = (string) $widget['metric'];
        $readinessMetric = $metric === 'current_open_tickets' ? 'historical_open_backlog' : $metric;
        $activation = $readinessByMetric[$readinessMetric] ?? [];
        $common = [
            'record_type' => 'metric_detail',
            'section' => 'Evidence detail - ' . $this->section($widget),
            'metric' => (string) $widget['title'],
            'interpretation' => 'Certified supporting observation',
            'period' => $this->period($report),
            'data_status' => $this->dataStatus($data, $activation),
            'evidence' => 'Supporting data for ' . (string) $widget['title'],
            'dashboard' => (string) $report['dashboard']['name'],
            'widget' => (string) $widget['title'],
            'metric_or_insight_key' => $metric,
            'unit' => $this->metricUnit($metric),
            'comparison_basis' => (string) ($activation['comparison_basis'] ?? 'Current value'),
            'activation_state' => (string) ($activation['activation_state'] ?? 'CURRENT_STATE'),
            'source_as_of' => $this->sourceAsOf($data),
            'provenance' => (string) ($data['provenance'] ?? ''),
            'effective_provenance' => (string) ($data['effective_provenance'] ?? ''),
            'coverage' => $this->coverage($activation),
            'entity_scope' => $scope,
        ];

        foreach ($data['series'] ?? [] as $point) {
            $this->row($stream, array_replace($common, [
                'date' => (string) ($point['date'] ?? ''),
                'dimension' => (string) ($point['dimension'] ?? ''),
                'value' => $point['value'] ?? '',
            ]));
        }
        foreach ($data['rows'] ?? [] as $record) {
            $dimension = (string) ($record['finding'] ?? $record['dimension'] ?? $record['title'] ?? '');
            if (isset($record['id'])) {
                $dimension = '#' . (int) $record['id'] . ($dimension === '' ? '' : ' ' . $dimension);
            }
            $evidence = array_filter([
                isset($record['state']) ? 'State: ' . (string) $record['state'] : null,
                isset($record['group']) ? 'Owner: ' . (string) $record['group'] : null,
                isset($record['timing']) ? 'Timing: ' . (string) $record['timing'] : null,
                isset($record['severity']) ? 'Severity: ' . (string) $record['severity'] : null,
            ]);
            $this->row($stream, array_replace($common, [
                'date' => (string) ($record['date'] ?? $report['to']),
                'dimension' => $dimension,
                'value' => $record['count'] ?? $record['value'] ?? '',
                'evidence' => $evidence === [] ? $common['evidence'] : implode('; ', $evidence),
            ]));
        }
        foreach ($data['matrix'] ?? [] as $cell) {
            $this->row($stream, array_replace($common, [
                'date' => (string) ($cell['date'] ?? $report['to']),
                'dimension' => trim((string) ($cell['row'] ?? '') . ' / ' . (string) ($cell['column'] ?? ''), ' /'),
                'value' => $cell['value'] ?? '',
            ]));
        }
    }

    /** @param array<string, mixed> $widget
     *  @param array<string, mixed> $data
     *  @return array{raw: mixed, display: string, interpretation: string, evidence: string, date?: string, dimension?: string}
     */
    private function widgetSummary(array $widget, array $data): array
    {
        $metric = (string) $widget['metric'];
        if (array_key_exists('value', $data)) {
            return [
                'raw' => $data['value'],
                'display' => $this->formatMetricValue($metric, $data['value']),
                'interpretation' => $this->sourceContext($data),
                'evidence' => $this->sourceContext($data),
                'date' => $this->sourceAsOf($data),
            ];
        }

        $series = $data['series'] ?? [];
        if ($series !== []) {
            $latestDate = (string) ($series[array_key_last($series)]['date'] ?? '');
            $latest = array_values(array_filter($series, static fn (array $point): bool => (string) ($point['date'] ?? '') === $latestDate));
            if ($latest === []) {
                $latest = [$series[array_key_last($series)]];
            }
            usort($latest, static fn (array $a, array $b): int => ((float) ($b['value'] ?? 0)) <=> ((float) ($a['value'] ?? 0)));
            if (($widget['type'] ?? '') === 'donut') {
                $total = array_sum(array_map(static fn (array $point): float => (float) ($point['value'] ?? 0), $latest));
                return [
                    'raw' => $total,
                    'display' => $this->formatMetricValue($metric, $total),
                    'interpretation' => 'Total represented in the certified snapshot distribution',
                    'evidence' => sprintf('%d categories at certified snapshot %s', count($latest), $latestDate),
                    'date' => $latestDate,
                ];
            }
            $leading = $latest[0];
            if (($leading['dimension'] ?? '') !== '') {
                return [
                    'raw' => $leading['value'] ?? '',
                    'display' => $this->formatMetricValue($metric, $leading['value'] ?? null),
                    'interpretation' => 'Leading category in the latest certified observation',
                    'evidence' => 'Leading category: ' . (string) $leading['dimension'],
                    'date' => $latestDate,
                    'dimension' => (string) $leading['dimension'],
                ];
            }
            return [
                'raw' => $leading['value'] ?? '',
                'display' => $this->formatMetricValue($metric, $leading['value'] ?? null),
                'interpretation' => 'Latest certified observation',
                'evidence' => 'Certified time-series endpoint',
                'date' => $latestDate,
            ];
        }

        $count = count($data['rows'] ?? $data['matrix'] ?? []);
        return [
            'raw' => '',
            'display' => $count === 0 ? 'No matching records' : $count . ' records',
            'interpretation' => $count === 0 ? 'No certified evidence rows are available' : 'Certified evidence rows included',
            'evidence' => $count === 0 ? 'No matching evidence' : $count . ' authorized evidence rows',
        ];
    }


    /** @param array<string, mixed> $data */
    private function sourceAsOf(array $data): string
    {
        $series = $data['series'] ?? [];
        return (string) ($data['as_of'] ?? ($series === [] ? '' : ($series[array_key_last($series)]['date'] ?? '')));
    }

    /** @param array<string, mixed> $data */
    private function sourceContext(array $data): string
    {
        $asOf = $this->sourceAsOf($data);
        if ((string) ($data['source'] ?? '') === 'live') {
            return 'Live value' . ($asOf === '' ? '' : ' as of ' . $asOf);
        }
        return 'Certified snapshot value' . ($asOf === '' ? '' : ' as of ' . $asOf);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $activation */
    private function dataStatus(array $data, array $activation): string
    {
        $state = (string) ($activation['activation_state'] ?? 'CURRENT_STATE');
        return $state === 'CURRENT_STATE'
            ? $this->sourceContext($data)
            : $this->activationLabel($state) . '; ' . $this->sourceContext($data);
    }
    /** @param array<string, mixed> $widget */
    private function section(array $widget): string
    {
        $metric = (string) ($widget['metric'] ?? '');
        return match (true) {
            in_array($metric, ['current_open_tickets', 'historical_open_backlog', 'unassigned_open_tickets', 'average_open_ticket_age', 'created_vs_resolved_tickets', 'technician_workload_distribution', 'open_tickets_by_priority', 'operational_attention'], true) => 'Service operations',
            in_array($metric, ['sla_breach_count', 'tickets_approaching_sla_breach', 'active_sla_exceptions', 'sla_breaches_by_technician', 'resolution_time_age_bands', 'open_tickets_priority_category_matrix', 'historical_group_backlog'], true) => 'SLA, age and ownership',
            in_array($metric, ['assignment_changes_per_ticket', 'unsatisfied_survey_responses', 'latest_solution_refused_tickets', 'tickets_by_request_source'], true) => 'Customer experience and request quality',
            in_array($metric, ['asset_inventory_total', 'stale_computer_inventory', 'low_disk_capacity_computers', 'computers_in_stock_over_30_days', 'incidents_by_operating_system', 'repeat_incident_computers'], true) => 'Asset exposure',
            str_contains($metric, 'software') || str_contains($metric, 'licence') || str_contains($metric, 'license') || str_contains($metric, 'entitlement') => 'Software and licence governance',
            str_contains($metric, 'change') || str_contains($metric, 'problem') => 'Change and problem control',
            default => 'Additional evidence',
        };
    }

    /** @param array<string, mixed> $report */
    private function period(array $report): string
    {
        return sprintf('%s to %s', (string) ($report['from'] ?? ''), (string) ($report['to'] ?? ''));
    }

    /** @param array<string, mixed> $activation */
    private function coverage(array $activation): string
    {
        if (isset($activation['available_days'], $activation['required_days'])) {
            return sprintf('%d of %d days available', (int) $activation['available_days'], (int) $activation['required_days']);
        }
        if (isset($activation['completed'], $activation['required'])) {
            return sprintf('%d of %d days available', (int) $activation['completed'], (int) $activation['required']);
        }
        return '';
    }

    private function activationLabel(string $state): string
    {
        return match ($state) {
            'CERTIFIED_PERIOD_COMPARISON' => 'Certified period comparison',
            'COMPARABLE_WINDOW' => 'Comparable window available',
            'OBSERVED_MOVEMENT' => 'Monitoring movement available',
            'CURRENT_STATE' => 'Current certified value',
            default => $state === '' ? 'Current certified value' : $this->label($state),
        };
    }

    private function direction(string $direction): string
    {
        return match ($direction) {
            'worsening' => 'Worsening',
            'improving' => 'Improving',
            default => 'Informational',
        };
    }

    /** @param array<string, mixed> $item */
    private function movement(array $item, string $unit): string
    {
        if (isset($item['percentage_point_change']) && $item['percentage_point_change'] !== null && $item['percentage_point_change'] !== '') {
            $value = (float) $item['percentage_point_change'];
            return ($value >= 0 ? '+' : '') . $this->formatNumber($value) . ' percentage points';
        }
        if (isset($item['absolute_change']) && $item['absolute_change'] !== null && $item['absolute_change'] !== '') {
            $value = (float) $item['absolute_change'];
            $absolute = ($value >= 0 ? '+' : '') . $this->formatAnalyticalValue($value, $unit);
            if (isset($item['relative_change_percent']) && $item['relative_change_percent'] !== null && $item['relative_change_percent'] !== '') {
                $relative = (float) $item['relative_change_percent'];
                return $absolute . ' (' . ($relative >= 0 ? '+' : '') . $this->formatNumber($relative) . '%)';
            }
            return $absolute;
        }
        return '';
    }

    private function formatAnalyticalValue(mixed $value, string $unit): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $number = (float) $value;
        return match ($unit) {
            'percent' => $this->formatNumber($number) . '%',
            'percentage_points' => $this->formatNumber($number) . ' percentage points',
            'seconds' => $number < 3600 ? $this->formatNumber($number / 60) . ' min' : $this->formatNumber($number / 3600) . ' hr',
            default => $this->formatNumber($number),
        };
    }

    private function formatMetricValue(string $metric, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not available';
        }
        $number = (float) $value;
        if (in_array($metric, ['average_open_ticket_age', 'average_unassigned_time'], true)) {
            return $this->formatNumber($number / 86400) . ' days';
        }
        if (in_array($metric, ['software_license_compliance_rate', 'sla_breach_rate', 'customer_dissatisfaction_rate', 'refused_solution_rate', 'repeat_incident_asset_rate', 'licence_utilization_rate', 'licence_coverage_gap_rate'], true)) {
            return $this->formatNumber($number) . '%';
        }
        if (in_array($metric, ['first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds'], true)) {
            return $number < 3600 ? $this->formatNumber($number / 60) . ' min' : $this->formatNumber($number / 3600) . ' hr';
        }
        return $this->formatNumber($number);
    }

    private function metricUnit(string $metric): string
    {
        return match (true) {
            in_array($metric, ['average_open_ticket_age', 'average_unassigned_time'], true) => 'days',
            in_array($metric, ['software_license_compliance_rate', 'sla_breach_rate', 'customer_dissatisfaction_rate', 'refused_solution_rate', 'repeat_incident_asset_rate', 'licence_utilization_rate', 'licence_coverage_gap_rate'], true) => 'percent',
            in_array($metric, ['first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds'], true) => 'seconds',
            default => 'count',
        };
    }

    private function formatNumber(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.00001 ? 0 : 1;
        return number_format($value, $decimals, '.', ',');
    }

    private function label(string $key): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $key)));
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
