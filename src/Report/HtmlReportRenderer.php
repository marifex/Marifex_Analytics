<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use GlpiPlugin\Marifex\Palette\PaletteRegistry;
use GlpiPlugin\Marifex\Palette\PaletteService;

final class HtmlReportRenderer
{
    /** @param array<string, mixed> $report */
    public function render(array $report): string
    {
        $name = $this->e((string) ($report['dashboard']['name'] ?? 'Analytics dashboard'));
        $insights = $report['insights'] ?? [];
        $readinessByMetric = array_column($insights['readiness']['metrics'] ?? [], null, 'metric');
        $kpis = [];
        $sections = [];
        foreach ($report['widgets'] as $item) {
            $widget = $item['definition'];
            if (($widget['type'] ?? '') === 'kpi' && count($kpis) < 6) {
                $kpis[] = $this->kpiTile($widget, $item['data'], $readinessByMetric);
                continue;
            }
            $sections[$this->section($widget)][] = ['html' => $this->widget($widget, $item['data']), 'type' => (string) ($widget['type'] ?? '')];
        }
        $range = $this->e(sprintf('%s to %s', $report['from'], $report['to']));
        $generated = $this->e((new \DateTimeImmutable($report['generated_at']))->format('Y-m-d H:i T'));
        $scope = $this->e((string) ($report['scope_label'] ?? ('Entity #' . (int) ($report['entities_id'] ?? 0))));
        $group = $report['group_label'] ?? null;
        $cutoff = $this->e((string) ($insights['cutoff'] ?? $report['to']));
        $horizon = (int) ($report['horizon_days'] ?? 0);
        $body = '<!doctype html><html><head><meta charset="utf-8"><style>' . $this->css() . $this->printCssOverrides() . $this->extendedPaletteCss() . '</style></head><body>'
            . '<section class="executive-page"><header class="cover-header"><div><p class="brand">MARIFEX ADVANCED ANALYTICS</p><h1>' . $name
            . '</h1><p class="sub">Executive performance report</p></div><div class="report-period"><span>REPORTING PERIOD</span><strong>'
            . $range . '</strong><small>Prepared ' . $generated . '</small></div></header><div class="scope-grid">'
            . $this->scopeItem('Organisation coverage', $scope)
            . $this->scopeItem('Reporting horizon', $horizon > 0 ? $horizon . ' days' : $range)
            . $this->scopeItem('Data current through', $cutoff)
            . $this->scopeItem('Service group', $group === null ? 'All authorized groups' : $this->e((string) $group))
            . '</div>' . $this->insightSummary($insights)
            . '<section class="key-metrics"><div class="section-title"><span>EXECUTIVE OVERVIEW</span><h2>Performance summary</h2></div>'
            . '<div class="kpi-strip">' . implode('', $kpis) . '</div></section></section>';
        foreach ($sections as $title => $cards) {
            foreach ($this->paginateSectionCards($cards) as $page => $pageCards) {
                $continued = $page === 0 ? '' : ' (continued)';
                $body .= '<section class="report-section"><div class="section-title"><span>PERFORMANCE DETAIL</span><h2>'
                    . $this->e($title . $continued) . '</h2></div><div class="report-grid">' . implode('', array_column($pageCards, 'html')) . '</div></section>';
            }
        }
        return $body . $this->confidenceAppendix($report) . '</body></html>';
    }

    /**
     * @param list<array{html: string, type: string}> $cards
     * @return list<list<array{html: string, type: string}>>
     */
    private function paginateSectionCards(array $cards): array
    {
        $pages = [];
        $page = [];
        $usedRows = 0;
        foreach (array_chunk($cards, 2) as $row) {
            $rowWeight = array_filter($row, static fn (array $card): bool => $card['type'] === 'detail_table') === [] ? 1 : 2;
            if ($page !== [] && $usedRows + $rowWeight > 2) {
                $pages[] = $page;
                $page = [];
                $usedRows = 0;
            }
            array_push($page, ...$row);
            $usedRows += $rowWeight;
            if ($usedRows >= 2) {
                $pages[] = $page;
                $page = [];
                $usedRows = 0;
            }
        }
        if ($page !== []) {
            $pages[] = $page;
        }
        return $pages;
    }

    private function scopeItem(string $label, string $value): string
    {
        return '<div><span>' . $this->e($label) . '</span><strong>' . $value . '</strong></div>';
    }

    /** @param array<string, mixed> $payload */
    private function insightSummary(array $payload): string
    {
        $items = '';
        foreach (array_slice($payload['insights'] ?? [], 0, 5) as $insight) {
            $direction = in_array($insight['direction'] ?? '', ['worsening', 'improving', 'neutral'], true) ? (string) $insight['direction'] : 'neutral';
            $calculation = $insight['calculation'] ?? [];
            $evidence = implode(' · ', array_filter([
                (string) ($insight['comparison_basis'] ?? ''),
                isset($calculation['formula_version']) ? $this->formulaVersionLabel((string) $calculation['formula_version']) : '',
                (string) ($insight['effective_provenance_label'] ?? ''),
            ]));
            $items .= '<li class="' . $direction . '"><span class="finding-state">' . $this->e($this->directionLabel($direction)) . '</span><div><strong>'
                . $this->e((string) ($insight['label'] ?? 'Analytical finding')) . '</strong><p>'
                . $this->e((string) ($insight['narrative'] ?? '')) . '</p><small>' . $this->e($evidence) . '</small></div></li>';
        }
        $readiness = $payload['readiness'] ?? [];
        if ($items === '') {
            $metrics = $readiness['metrics'] ?? [];
            $required = (int) ($readiness['required_snapshots'] ?? 0);
            $available = $metrics === [] ? 0 : min(array_map(static fn (array $metric): int => (int) ($metric['completed'] ?? $metric['available_days'] ?? 0), $metrics));
            $pending = count(array_filter($metrics, static fn (array $metric): bool => !($metric['ready'] ?? false)));
            $headline = $required > 0 ? sprintf('%d-day comparison history is building', max(1, (int) ($required / 2))) : 'Current performance position';
            $detail = $required > 0
                ? sprintf('%d of %d consecutive certified days are available. %d measure%s %s building comparison history.', $available, $required, $pending, $pending === 1 ? '' : 's', $pending === 1 ? 'is' : 'are')
                : 'Period comparisons will appear when the required comparison history is complete.';
            $items = '<li class="readiness"><span class="finding-state">COMPARISON STATUS</span><div><strong>' . $this->e($headline) . '</strong><p>'
                . $this->e($detail) . '</p><small>Current results remain available while comparison history builds.</small></div></li>';
        }
        $observed = '';
        foreach (array_slice($payload['observed_movements'] ?? [], 0, 3) as $movement) {
            $change = (float) ($movement['absolute_change'] ?? 0);
            $observed .= '<div><strong>' . $this->e((string) ($movement['label'] ?? $this->label((string) $movement['metric']))) . '</strong><span>'
                . $this->e(sprintf('%s by %s since monitoring began', $change >= 0 ? 'Increased' : 'Decreased', number_format(abs($change)))) . '</span></div>';
        }
        return '<section class="executive-brief"><div class="brief-heading"><div><span>EXECUTIVE BRIEF</span><h2>Executive insight brief</h2></div><small>Data current through '
            . $this->e((string) ($payload['cutoff'] ?? '')) . '</small></div><ol>' . $items . '</ol>'
            . ($observed === '' ? '' : '<div class="monitoring-context"><span>SINCE MONITORING BEGAN</span>' . $observed . '</div>') . '</section>';
    }

    /** @param array<string, mixed> $widget
     *  @param array<string, mixed> $data
     */
    private function widget(array $widget, array $data): string
    {
        $title = $this->e((string) $widget['title']);
        $palette = $this->surfacePalette($widget);
        $type = (string) ($widget['type'] ?? '');
        $chartPalette = isset($widget['chartPalette']) && class_exists(\Session::class) ? (new PaletteService())->resolve((string) $widget['chartPalette']) : null;
        $fallback = PaletteRegistry::builtIns()[PaletteRegistry::SURFACE_TO_CHART[$palette]]['colors'];
        $colors = is_array($chartPalette['colors'] ?? null) ? $chartPalette['colors'] : $fallback;
        $metric = (string) ($widget['metric'] ?? '');
        $body = match ($widget['type']) {
            'kpi' => '<div class="kpi">' . $this->e($this->kpi($widget['metric'], $data)) . '</div><p class="context">' . $this->e($this->sourceContext($data)) . '</p>',
            'line' => $this->line($data['series'] ?? [], $colors[0], $metric),
            'bar' => $this->bars($data['series'] ?? [], $colors[0], $metric),
            'donut' => $this->donut($data['series'] ?? [], $colors, $this->sourceContext($data, true), $metric),
            'table' => $this->table($data['series'] ?? [], $metric),
            'insight' => $this->insight($data['series'] ?? []),
            'attention' => $this->attention($data['rows'] ?? []),
            'detail_table' => $this->detailTable($data['rows'] ?? []),
            'matrix' => $this->matrix($data['matrix'] ?? []),
            default => '',
        };
        $class = 'report-card report-card-' . $type . ' palette-' . $palette;
        return '<article class="' . $class . '"><h3>' . $title . '</h3>' . $body . '</article>';
    }

    /** @param array<string, mixed> $widget
     *  @param array<string, mixed> $data
     *  @param array<string, array<string, mixed>> $readinessByMetric
     */
    private function kpiTile(array $widget, array $data, array $readinessByMetric): string
    {
        $metric = (string) $widget['metric'];
        $readinessMetric = $metric === 'current_open_tickets' ? 'historical_open_backlog' : $metric;
        $activation = $readinessByMetric[$readinessMetric] ?? [];
        $activationState = (string) ($activation['activation_state'] ?? 'CURRENT_STATE');
        $status = $activationState === 'CURRENT_STATE'
            ? $this->sourceContext($data)
            : $this->activationLabel($activationState) . ' · ' . $this->sourceContext($data);
        return '<article class="kpi-tile palette-' . $this->surfacePalette($widget) . '"><h3>'
            . $this->e((string) $widget['title']) . '</h3><strong>' . $this->e($this->kpi($metric, $data))
            . '</strong><span>' . $this->e($status) . '</span></article>';
    }

    /** @param array<string, mixed> $widget */
    private function surfacePalette(array $widget): string
    {
        return isset(PaletteRegistry::SURFACE_TO_CHART[$widget['palette'] ?? '']) ? (string) $widget['palette'] : 'neutral';
    }


    /** @param array<string, mixed> $data */
    private function sourceContext(array $data, bool $distribution = false): string
    {
        $source = (string) ($data['source'] ?? '');
        $series = $data['series'] ?? [];
        $asOf = (string) ($data['as_of'] ?? ($series === [] ? '' : ($series[array_key_last($series)]['date'] ?? '')));
        if ($asOf !== '') {
            $asOf = str_replace('T', ' ', substr($asOf, 0, 16));
        }
        if ($source === 'live') {
            return 'Live value' . ($asOf === '' ? '' : ' · as of ' . $asOf . ' UTC');
        }
        $label = $distribution ? 'Certified snapshot distribution' : 'Certified snapshot value';
        return $label . ($asOf === '' ? '' : ' · as of ' . $asOf);
    }
    /** @param array<string, mixed> $data */
    private function kpi(string $metric, array $data): string
    {
        $series = $data['series'] ?? [];
        $value = $data['value'] ?? ($series === [] ? null : $series[array_key_last($series)]['value']);
        if ($value === null) {
            return 'Not available';
        }
        if (in_array($metric, ['average_open_ticket_age', 'average_unassigned_time'], true)) {
            return number_format(((float) $value) / 86400, 1) . ' days';
        }
        if (in_array($metric, ['software_license_compliance_rate', 'sla_breach_rate', 'customer_dissatisfaction_rate', 'refused_solution_rate', 'repeat_incident_asset_rate', 'licence_utilization_rate', 'licence_coverage_gap_rate'], true)) {
            return number_format((float) $value, 1) . '%';
        }
        if (in_array($metric, ['first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds'], true)) {
            return (float) $value < 3600 ? number_format((float) $value / 60, 1) . ' min' : number_format((float) $value / 3600, 1) . ' hr';
        }
        return number_format((float) $value, is_float($value) ? 1 : 0);
    }

    /** @param list<array<string, mixed>> $series */
    private function line(array $series, string $color, string $metric): string
    {
        if ($series === []) {
            return $this->emptyState($metric);
        }
        $values = array_map(static fn(array $point): float => (float) $point['value'], $series);
        $min = min($values); $max = max($values); $span = max(1.0, $max - $min); $count = max(1, count($values) - 1);
        $points = [];
        foreach ($values as $index => $value) {
            $x = 28 + (584 * $index / $count);
            $y = 155 - (125 * ($value - $min) / $span);
            $points[] = sprintf('%.1f,%.1f', $x, $y);
        }
        return '<svg class="chart" viewBox="0 0 640 180" role="img"><line x1="28" y1="155" x2="612" y2="155" class="axis"/><polyline points="' . implode(' ', $points) . '" class="line" style="stroke:' . $color . '"/></svg>'
            . '<div class="chart-labels"><span>' . $this->e((string) $series[0]['date']) . '</span><span>' . $this->e((string) $series[array_key_last($series)]['date']) . '</span></div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function bars(array $series, string $color, string $metric): string
    {
        $latest = $this->latestDimensions($series);
        if ($latest === []) return $this->emptyState($metric);
        $max = max(array_column($latest, 'value')) ?: 1;
        $html = '<div class="bars">';
        foreach (array_slice($latest, 0, 10) as $point) {
            $width = max(1, ((float) $point['value'] / $max) * 100);
            $html .= '<div class="bar-row"><span>' . $this->e((string) $point['dimension']) . '</span><i><b style="width:' . round($width, 1) . '%;background:' . $color . '"></b></i><strong>' . number_format((float) $point['value']) . '</strong></div>';
        }
        return $html . '</div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function donut(array $series, array $colors, string $context, string $metric): string
    {
        $latest = array_slice($this->latestDimensions($series), 0, 10);
        if ($latest === []) return $this->emptyState($metric);
        $total = array_sum(array_column($latest, 'value')) ?: 1; $cursor = 0; $stops = []; $legend = '';
        foreach ($latest as $index => $point) {
            $start = $cursor; $cursor += ((float) $point['value'] / $total) * 100; $color = $colors[$index % count($colors)];
            $stops[] = sprintf('%s %.2f%% %.2f%%', $color, $start, $cursor);
            $legend .= '<div><i style="background:' . $color . '"></i><span>' . $this->e((string) $point['dimension']) . '</span><strong>' . number_format((float) $point['value']) . '</strong></div>';
        }
        return '<div class="donut-wrap"><div class="donut" style="background:conic-gradient(' . implode(',', $stops) . ')"><span>' . number_format($total) . '</span></div><div class="legend">' . $legend . '</div></div><p class="context distribution-context">'
            . $this->e($context . ' · ' . number_format($total) . ' tickets represented') . '</p>';
    }

    /** @param list<array<string, mixed>> $series */
    private function table(array $series, string $metric): string
    {
        $latest = $this->latestDimensions($series);
        if ($latest === []) return $this->emptyState($metric);
        $html = '<table><thead><tr><th>' . $this->e($this->categoryLabel($metric)) . '</th><th>Value</th></tr></thead><tbody>';
        foreach (array_slice($latest, 0, 12) as $point) {
            $html .= '<tr><td>' . $this->e((string) $point['dimension']) . '</td><td>' . number_format((float) $point['value']) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function categoryLabel(string $metric): string
    {
        return match ($metric) {
            'prohibited_software_installations', 'software_installations_above_entitlement' => 'Software',
            'open_incidents_by_assignment_group', 'historical_group_backlog' => 'Assignment group',
            'incidents_by_operating_system' => 'Operating system',
            'tickets_by_request_source', 'created_tickets_by_request_source' => 'Request source',
            default => 'Category',
        };
    }

    private function emptyState(string $metric): string
    {
        $message = match ($metric) {
            'prohibited_software_installations' => 'No software is marked invalid in the selected scope.',
            'software_installations_above_entitlement' => 'No installations exceed the recorded entitlement in the selected scope.',
            'open_incidents_by_assignment_group' => 'No open incidents are assigned to a service group in the selected scope.',
            'incidents_by_operating_system' => 'No operating-system incident data is available for the selected period.',
            default => 'No records are available for the selected period and scope.',
        };
        return '<div class="empty-state"><strong>No reportable records</strong><span>' . $this->e($message) . '</span></div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function insight(array $series): string
    {
        $latest = $this->latestDimensions($series);
        if ($latest === []) return '<p class="empty">No finding is available for this period.</p>';
        $point = $latest[0];
        return '<div class="insight"><span>Leading finding</span><strong>'
            . $this->e((string) $point['dimension']) . '</strong><p>'
            . number_format((float) $point['value']) . ' current records</p></div>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function attention(array $rows): string
    {
        if ($rows === []) return '<p class="empty">No operational findings are available.</p>';
        $html = '<div class="attention">';
        foreach (array_slice($rows, 0, 10) as $row) {
            $severity = in_array($row['severity'] ?? '', ['critical', 'warning', 'info'], true) ? (string) $row['severity'] : 'info';
            $html .= '<div><i class="severity-' . $severity . '"></i><span>'
                . $this->e((string) ($row['finding'] ?? 'Finding')) . '</span><strong>'
                . number_format((float) ($row['count'] ?? 0)) . '</strong></div>';
        }
        return $html . '</div>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function detailTable(array $rows): string
    {
        if ($rows === []) return '<p class="empty">No matching records in this period.</p>';
        $html = '<table class="detail-table"><thead><tr><th>Record</th><th>Priority / state</th><th>Owner</th><th>Timing</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 8) as $row) {
            $record = '#' . (int) ($row['id'] ?? 0) . ' ' . (string) ($row['title'] ?? 'Record');
            $state = (string) ($row['state'] ?? ('Priority ' . (int) ($row['priority'] ?? 0)));
            $timing = (string) ($row['timing'] ?? ($row['latest_solution_date'] ?? ''));
            $html .= '<tr><td>' . $this->e($record) . '</td><td>' . $this->e($state) . '</td><td>'
                . $this->e((string) ($row['group'] ?? 'Unassigned')) . '</td><td>' . $this->e($timing) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param list<array<string, mixed>> $cells */
    private function matrix(array $cells): string
    {
        if ($cells === []) return '<p class="empty">No matrix data in this range.</p>';
        $rows = []; $columns = []; $values = [];
        foreach ($cells as $cell) {
            $rowId = (string) ($cell['row_id'] ?? ''); $columnId = (string) ($cell['column_id'] ?? '');
            $rows[$rowId] = (string) ($cell['row'] ?? $rowId);
            $columns[$columnId] = (string) ($cell['column'] ?? $columnId);
            $values[$rowId][$columnId] = (float) ($cell['value'] ?? 0);
        }
        $columns = array_slice($columns, 0, 6, true);
        $html = '<table class="matrix"><thead><tr><th>Priority</th>';
        foreach ($columns as $label) $html .= '<th>' . $this->e($label) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $rowId => $label) {
            $html .= '<tr><th>' . $this->e($label) . '</th>';
            foreach ($columns as $columnId => $_) $html .= '<td>' . number_format((float) ($values[$rowId][$columnId] ?? 0)) . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param list<array<string, mixed>> $series
     *  @return list<array<string, mixed>>
     */
    private function latestDimensions(array $series): array
    {
        $latest = $series === [] ? null : $series[array_key_last($series)]['date'];
        $rows = array_values(array_filter($series, static fn(array $point): bool => ($point['date'] ?? null) === $latest));
        usort($rows, static fn(array $a, array $b): int => ((float) $b['value']) <=> ((float) $a['value']));
        return $rows;
    }

    /** @param array<string, mixed> $report */
    private function confidenceAppendix(array $report): string
    {
        $payload = $report['insights'] ?? [];
        $readiness = $payload['readiness'] ?? [];
        $activation = $readiness['activation_counts'] ?? [];
        $versions = $payload['formula_versions'] ?? array_filter([(string) ($payload['formula_version'] ?? '')]);
        $versionBadges = '';
        foreach ($versions as $version) {
            $versionBadges .= '<span>' . $this->e($this->formulaVersionLabel((string) $version)) . '</span>';
        }
        $readinessRows = '';
        foreach ($readiness['metrics'] ?? [] as $metric) {
            $completed = (int) ($metric['completed'] ?? $metric['available_days'] ?? 0);
            $required = (int) ($metric['required'] ?? $metric['required_days'] ?? 0);
            $readinessRows .= '<tr><td>' . $this->e($this->label((string) ($metric['metric'] ?? 'Measure'))) . '</td><td>'
                . $this->e($this->activationLabel((string) ($metric['activation_state'] ?? 'CURRENT_STATE'))) . '</td><td>'
                . ($required > 0 ? sprintf('%d of %d days', $completed, $required) : 'Current value') . '</td><td>'
                . $this->e($this->provenanceLabel((string) ($metric['effective_provenance'] ?? $metric['effective_provenance_label'] ?? 'OBSERVED'))) . '</td></tr>';
        }
        $suppressionCounts = [];
        foreach ($payload['suppressed'] ?? [] as $suppressed) {
            $message = $this->suppressionLabel((string) ($suppressed['code'] ?? ''), (string) ($suppressed['message'] ?? ''));
            $suppressionCounts[$message] = ($suppressionCounts[$message] ?? 0) + 1;
        }
        $suppressions = '';
        foreach ($suppressionCounts as $message => $count) {
            $suppressions .= '<li><strong>' . $count . '</strong><span>' . $this->e($message) . '</span></li>';
        }
        if ($suppressions === '') {
            $suppressions = '<li><strong>0</strong><span>All eligible comparisons are shown.</span></li>';
        }
        $scope = $this->e((string) ($report['scope_label'] ?? ('Entity #' . (int) ($report['entities_id'] ?? 0))));
        return '<section class="confidence-appendix"><div class="section-title"><span>REPORT NOTES</span><h2>Data coverage and calculation notes</h2></div>'
            . '<div class="confidence-summary"><div><span>Organisation coverage</span><strong>' . $scope . '</strong></div><div><span>Data current through</span><strong>'
            . $this->e((string) ($payload['cutoff'] ?? $report['to'])) . '</strong></div><div><span>Calculation standards</span><strong class="badges">'
            . $versionBadges . '</strong></div><div><span>Comparison availability</span><strong>'
            . (int) ($activation['CERTIFIED_PERIOD_COMPARISON'] ?? 0) . ' measures with a period comparison · '
            . (int) ($activation['COMPARABLE_WINDOW'] ?? 0) . ' measures with one complete period · '
            . (int) ($activation['OBSERVED_MOVEMENT'] ?? 0) . ' measures tracked since monitoring began · '
            . (int) ($activation['CURRENT_STATE'] ?? 0) . ' current-only measures</strong></div></div>'
            . '<h3 class="appendix-heading">Measure availability and data origin</h3><table class="confidence-table"><thead><tr><th>Measure</th><th>Analysis available</th><th>History available</th><th>Data origin</th></tr></thead><tbody>'
            . $readinessRows . '</tbody></table><h3 class="appendix-heading">Comparisons not shown</h3><ul class="suppression-list">'
            . $suppressions . '</ul><p class="appendix-note">This report is read-only. Reported findings use the same approved calculations, access controls, data-currentness checks and minimum data requirements as the dashboard. The companion CSV and report history retain the detailed calculation record for audit and reconciliation.</p></section>';
    }

    private function formulaVersionLabel(string $version): string
    {
        return match (strtolower($version)) {
            'phase5a-1' => 'Core operational measures, version 1',
            'phase5b-1' => 'Quality and demand measures, version 1',
            'phase5a-1+phase5b-1' => 'Operational, quality and demand measures, version 1',
            default => 'Calculation standard ' . $version,
        };
    }

    private function suppressionLabel(string $code, string $message): string
    {
        return match ($code) {
            'INSUFFICIENT_HISTORY' => 'Comparison history is still building.',
            'DENOMINATOR_BELOW_MINIMUM' => 'The available population is below the minimum required for a reliable comparison.',
            'NO_ACTIVITY' => 'No activity was recorded in either comparison period.',
            'NO_MATERIAL_CHANGE' => 'The movement did not meet the approved reporting threshold.',
            'STALE_SOURCE' => 'The latest certified source update is pending.',
            'MISSING_SOURCE', 'UNAVAILABLE_SOURCE' => 'A required certified source is not currently available.',
            default => $message === '' ? 'The comparison is not currently available.' : $message,
        };
    }

    private function provenanceLabel(string $provenance): string
    {
        return match (strtoupper(str_replace(' ', '_', $provenance))) {
            'OBSERVED' => 'Recorded in GLPI',
            'CERTIFIED_BOOTSTRAP' => 'Certified historical reconstruction',
            'DERIVED' => 'Calculated from certified records',
            'UNCERTIFIED_RECONSTRUCTION' => 'Uncertified historical estimate',
            default => $provenance === '' ? 'Recorded in GLPI' : $provenance,
        };
    }

    /** @param array<string, mixed> $widget */
    private function section(array $widget): string
    {
        $metric = (string) ($widget['metric'] ?? '');
        return match (true) {
            in_array($metric, ['current_open_tickets', 'historical_open_backlog', 'unassigned_open_tickets', 'average_open_ticket_age', 'created_vs_resolved_tickets', 'technician_workload_distribution', 'open_tickets_by_priority', 'operational_attention'], true) => 'Service operations',
            in_array($metric, ['sla_breach_count', 'tickets_approaching_sla_breach', 'active_sla_exceptions', 'sla_breaches_by_technician', 'resolution_time_age_bands', 'open_tickets_priority_category_matrix'], true) => 'SLA, age and ownership',
            in_array($metric, ['open_incidents_by_assignment_group', 'historical_group_backlog'], true) => 'Workload and ownership',
            in_array($metric, ['assignment_changes_per_ticket', 'unsatisfied_survey_responses', 'latest_solution_refused_tickets', 'tickets_by_request_source'], true) => 'Customer experience and request quality',
            in_array($metric, ['asset_inventory_total', 'stale_computer_inventory', 'low_disk_capacity_computers', 'computers_in_stock_over_30_days', 'incidents_by_operating_system', 'repeat_incident_computers'], true) => 'Asset exposure',
            str_contains($metric, 'software') || str_contains($metric, 'licence') || str_contains($metric, 'license') || str_contains($metric, 'entitlement') => 'Software and licence governance',
            str_contains($metric, 'change') || str_contains($metric, 'problem') => 'Change and problem control',
            default => 'Additional evidence',
        };
    }

    private function activationLabel(string $state): string
    {
        return match ($state) {
            'CERTIFIED_PERIOD_COMPARISON' => 'Certified period comparison',
            'COMPARABLE_WINDOW' => 'One complete reporting period available',
            'OBSERVED_MOVEMENT' => 'Change since monitoring began available',
            'CURRENT_STATE' => 'Current result available',
            default => $state === '' ? 'Current result available' : $this->label($state),
        };
    }

    private function directionLabel(string $direction): string
    {
        return match ($direction) {
            'worsening' => 'WORSENING',
            'improving' => 'IMPROVING',
            default => 'INFORMATIONAL',
        };
    }

    private function label(string $key): string
    {
        $specific = match ($key) {
            'historical_open_backlog' => 'Open backlog',
            'created_vs_resolved_tickets' => 'Created versus resolved tickets',
            'historical_group_backlog' => 'Open backlog by assignment group',
            'technician_workload_distribution' => 'Technician workload',
            default => null,
        };
        if ($specific !== null) {
            return $specific;
        }
        $label = ucwords(strtolower(str_replace('_', ' ', $key)));
        return trim(str_replace([' Sla ', ' Itil ', ' Glpi ', ' Os ', ' Vs '], [' SLA ', ' ITIL ', ' GLPI ', ' OS ', ' versus '], ' ' . $label . ' '));
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private function extendedPaletteCss(): string
    {
        return '.palette-classic_blue{--card-bg:#eff6ff;--card-end:#dbeafe;--card-border:#bfdbfe;--card-muted:#475569;--card-text:#1e3a8a}'
            . '.palette-teal_green{--card-bg:#ecfdf5;--card-end:#d1fae5;--card-border:#a7f3d0;--card-muted:#475569;--card-text:#064e3b}'
            . '.palette-deep_purple{--card-bg:#f5f3ff;--card-end:#ede9fe;--card-border:#ddd6fe;--card-muted:#475569;--card-text:#4c1d95}'
            . '.palette-warm_amber{--card-bg:#fffbeb;--card-end:#fef3c7;--card-border:#fde68a;--card-muted:#64748b;--card-text:#78350f}'
            . '.palette-coral_red{--card-bg:#fef2f2;--card-end:#fee2e2;--card-border:#fecaca;--card-muted:#64748b;--card-text:#7f1d1d}'
            . '.palette-sky_blue{--card-bg:#f0f9ff;--card-end:#e0f2fe;--card-border:#bae6fd;--card-muted:#475569;--card-text:#0c4a6e}'
            . '.palette-bright_orange{--card-bg:#fff7ed;--card-end:#ffedd5;--card-border:#fed7aa;--card-muted:#64748b;--card-text:#7c2d12}'
            . '.palette-rose_pink{--card-bg:#fff5f5;--card-end:#ffe4e6;--card-border:#fecdd3;--card-muted:#475569;--card-text:#881337}'
            . '.palette-forest_green{--card-bg:#f0fdf4;--card-end:#dcfce7;--card-border:#bbf7d0;--card-muted:#475569;--card-text:#14532d}'
            . '.palette-slate_gray{--card-bg:#f8fafc;--card-end:#f1f5f9;--card-border:#e2e8f0;--card-muted:#475569;--card-text:#0f172a}';
    }

    private function printCssOverrides(): string
    {
        return '@page{size:A4 landscape;margin:12mm 10mm}'
            . 'html,body{font-family:Arial,"Helvetica Neue",sans-serif;font-size:11px;line-height:1.35;color:#172033}'
            . '.executive-page{break-after:page;page-break-after:always}'
            . '.report-section{break-before:page;page-break-before:always;break-after:page;page-break-after:always;break-inside:avoid-page;page-break-inside:avoid;margin:0;padding:0}.confidence-appendix{break-before:page;page-break-before:always;margin:0;padding:0}.section-title{break-after:avoid-page;page-break-after:avoid}'
            . '.cover-header{padding-top:1mm}.brand{font-size:9px}.sub{font-size:10px;color:#475569}.report-period small{display:block;margin-top:5px;color:#475569;font-size:9px}'
            . '.report-period span,.scope-grid span,.section-title span,.brief-heading span,.monitoring-context>span,.confidence-summary span{font-size:8.5px;line-height:1.25}'
            . '.scope-grid strong,.confidence-summary strong{font-size:10px;line-height:1.35}'
            . '.brief-heading h2,.section-title h2{font-size:17px;line-height:1.2}'
            . '.brief-heading small{font-size:9px;color:#475569}'
            . '.finding-state{font-size:8.5px}.executive-brief li strong{font-size:11px}.executive-brief li p{font-size:10px;line-height:1.4}.executive-brief li small{font-size:9px;color:#475569}'
            . '.monitoring-context{align-items:flex-start;flex-wrap:wrap}.monitoring-context div span{font-size:9px}'
            . '.kpi-tile h3{font-size:10px}.kpi-tile>strong{font-size:26px}.kpi-tile>span{font-size:9px;line-height:1.3}'
            . '.report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}'
            . '.report-card{min-height:0;padding:11px;break-inside:avoid-page;page-break-inside:avoid;overflow-wrap:anywhere}'
            . '.report-card-line,.report-card-bar,.report-card-donut{min-height:210px}'
            . '.report-card-table,.report-card-detail_table,.report-card-matrix,.report-card-attention{min-height:190px}'
            . '.report-card h3{margin:0 0 10px;padding-bottom:7px;font-size:13px;line-height:1.25}'
            . '.context,.empty{font-size:10px;color:#475569}.chart{height:140px}.chart-labels{font-size:9px;color:#475569}'
            . '.bars{gap:7px}.bar-row{grid-template-columns:minmax(130px,34%) 1fr 48px;gap:8px;font-size:9.5px;line-height:1.25}.bar-row span{overflow-wrap:anywhere}.bar-row i{height:9px}'
            . '.legend{gap:7px}.legend div{grid-template-columns:10px 1fr 42px;gap:7px;font-size:9.5px;line-height:1.25}.legend i{width:9px;height:9px}'
            . '.insight span{font-size:8.5px}.insight strong{font-size:19px}.insight p{font-size:10px}'
            . '.attention{gap:6px}.attention div{grid-template-columns:8px 1fr 48px;gap:7px;font-size:9.5px;line-height:1.25}'
            . 'table{font-size:9.5px;line-height:1.3;table-layout:fixed}th,td{padding:6px;overflow-wrap:anywhere}th{color:#334155}.detail-table,.matrix{font-size:8.5px}'
            . '.empty-state{min-height:125px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:18px;text-align:center;color:#475569;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:5px}'
            . '.empty-state strong{font-size:12px;color:#172033}.empty-state span{max-width:420px;font-size:10px;line-height:1.4}'
            . '.confidence-summary{gap:10px}.badges{display:flex!important;flex-wrap:wrap;gap:6px}.badges span{padding:4px 8px;font-size:9px;line-height:1.3}'
            . '.appendix-heading{margin:14px 0 8px;font-size:13px}.confidence-table{font-size:9px}.suppression-list{grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 12px;font-size:9px;line-height:1.35}.suppression-list li{grid-template-columns:24px 1fr;padding:5px}'
            . '.appendix-note{font-size:9.5px;line-height:1.45}';
    }

    private function css(): string
    {
        return '@page{size:A4 landscape;margin:14mm 10mm 12mm}*{box-sizing:border-box}html,body{margin:0;color:#1f2937;font-family:Arial,sans-serif;background:#fff;font-size:10px}.running-header{position:fixed;top:-9mm;left:0;right:0;display:flex;justify-content:space-between;border-bottom:1px solid #e5e7eb;padding-bottom:3px;color:#64748b;font-size:7px}.running-header span{font-weight:800;color:#b77900}footer{position:fixed;bottom:-8mm;left:0;right:0;display:flex;justify-content:space-between;border-top:1px solid #e5e7eb;padding-top:3px;color:#64748b;font-size:7px}.executive-page{break-after:page}.cover-header{display:flex;justify-content:space-between;align-items:flex-start;padding:4px 0 12px;border-bottom:2px solid #dbe2ea}.brand{margin:0 0 5px;color:#b77900;font-size:8px;font-weight:800;letter-spacing:1.4px}h1{margin:0;font-size:25px;color:#172033}.sub{margin:5px 0 0;color:#64748b}.report-period{text-align:right}.report-period span,.scope-grid span,.section-title span,.brief-heading span,.monitoring-context>span,.confidence-summary span{display:block;color:#64748b;font-size:7px;font-weight:800;letter-spacing:.7px;text-transform:uppercase}.report-period strong{display:block;margin-top:4px;font-size:12px}.scope-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:10px 0}.scope-grid>div,.confidence-summary>div{border:1px solid #e2e8f0;border-radius:5px;padding:7px 9px;background:#f8fafc}.scope-grid strong,.confidence-summary strong{display:block;margin-top:4px;font-size:9px}.executive-brief{border:1px solid #e5c467;border-left:4px solid #e0a000;border-radius:7px;padding:9px 11px;background:#fffdf6;break-inside:avoid}.brief-heading{display:flex;justify-content:space-between}.brief-heading h2,.section-title h2{margin:3px 0 0;color:#172033;font-size:15px}.brief-heading small{color:#64748b}.executive-brief ol{display:grid;grid-template-columns:repeat(2,1fr);gap:6px 12px;list-style:none;margin:8px 0 0;padding:0}.executive-brief li{display:grid;grid-template-columns:68px 1fr;gap:8px;border-top:1px solid #eadfbf;padding-top:6px}.executive-brief li.readiness{grid-column:1/-1}.finding-state{font-size:7px;font-weight:800;color:#475569}.worsening .finding-state{color:#b91c1c}.improving .finding-state{color:#047857}.executive-brief li strong{font-size:10px}.executive-brief li p{margin:2px 0;color:#334155;line-height:1.35}.executive-brief li small{color:#64748b;font-size:7px}.monitoring-context{display:flex;gap:12px;align-items:center;border-top:1px solid #eadfbf;margin-top:7px;padding-top:6px}.monitoring-context>div{display:grid}.monitoring-context div span{font-size:8px;color:#475569}.key-metrics{margin-top:12px}.section-title{border-bottom:1px solid #dbe2ea;padding-bottom:5px;margin-bottom:8px}.kpi-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.kpi-tile{--card-bg:#fff;--card-end:#fff;--card-border:#e2e8f0;--card-muted:#64748b;--card-text:#172033;position:relative;overflow:hidden;border:1px solid var(--card-border);border-radius:7px;padding:9px 11px;background:linear-gradient(135deg,var(--card-bg),var(--card-end));min-height:82px}.kpi-tile:before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--card-text);opacity:.75}.kpi-tile h3{margin:0;font-size:9px;color:var(--card-text)}.kpi-tile>strong{display:block;margin-top:8px;font-size:25px;line-height:1;color:var(--card-text)}.kpi-tile>span{display:block;margin-top:6px;color:var(--card-muted);font-size:8px}.report-section{margin-bottom:12px}.report-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.report-card{--card-border:#dfe4ec;border:1px solid #dfe4ec;border-top:3px solid var(--card-border);border-radius:7px;background:#fff;padding:9px;break-inside:avoid;min-height:105px}.report-card-line,.report-card-bar,.report-card-donut{min-height:195px}.report-card-table,.report-card-detail_table,.report-card-matrix,.report-card-attention{min-height:175px}.report-card h3{margin:0 0 8px;padding-bottom:6px;border-bottom:1px solid #e5e7eb;font-size:11px}.kpi{font-size:26px;font-weight:800;margin-top:14px}.context,.empty{color:#64748b;font-size:9px}.chart{width:100%;height:132px}.axis{stroke:#cbd5e1}.line{fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.chart-labels{display:flex;justify-content:space-between;color:#64748b;font-size:8px}.bars{display:grid;gap:5px}.bar-row{display:grid;grid-template-columns:130px 1fr 42px;gap:7px;align-items:center;font-size:8px}.bar-row i{height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden}.bar-row b{display:block;height:100%;border-radius:4px}.bar-row strong{text-align:right}.donut-wrap{display:grid;grid-template-columns:145px 1fr;gap:20px;align-items:center}.donut{width:125px;height:125px;border-radius:50%;position:relative;display:grid;place-items:center}.donut:after{content:"";position:absolute;inset:29px;background:#fff;border-radius:50%}.donut span{z-index:1;font-size:16px;font-weight:800}.legend{display:grid;gap:5px}.legend div{display:grid;grid-template-columns:9px 1fr 38px;gap:6px;align-items:center;font-size:8px}.legend i{width:8px;height:8px}.legend strong{text-align:right}.insight{display:grid;gap:6px}.insight span{color:#64748b;font-size:7px;text-transform:uppercase}.insight strong{font-size:18px}.insight p{margin:0;color:#64748b}.attention{display:grid;gap:4px}.attention div{display:grid;grid-template-columns:7px 1fr 40px;gap:6px;align-items:center;font-size:8px}.attention i{width:6px;height:6px;border-radius:50%}.severity-critical{background:#dc2626}.severity-warning{background:#d97706}.severity-info{background:#2563eb}.attention strong{text-align:right}table{width:100%;border-collapse:collapse;font-size:8px}th,td{padding:4px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}th{color:#475569}th:last-child,td:last-child{text-align:right}.detail-table,.matrix{font-size:7px}.matrix th:not(:first-child),.matrix td{text-align:center}.confidence-appendix{break-before:page}.confidence-summary{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.badges{display:flex!important;gap:5px}.badges span{background:#e2e8f0;border-radius:10px;padding:2px 7px;font-size:8px}.appendix-heading{margin:12px 0 6px;font-size:11px}.suppression-list{display:grid;grid-template-columns:repeat(3,1fr);gap:3px 9px;list-style:none;margin:0;padding:0;font-size:6.5px}.suppression-list li{display:grid;grid-template-columns:20px 1fr;gap:4px;border-bottom:1px solid #e5e7eb;padding:2px}.suppression-list strong{color:#b77900}.appendix-note{margin-top:10px;border-left:3px solid #b77900;padding:7px 9px;background:#fffaf0;color:#475569;font-size:8px}.palette-cream_gold{--card-bg:#fffdf4;--card-end:#fff4cf;--card-border:#e5bd4d;--card-muted:#776943;--card-text:#3b3321}.palette-ocean{--card-bg:#f5fbff;--card-end:#e7f4ff;--card-border:#7fb9e5;--card-muted:#55728d;--card-text:#17334f}.palette-mint{--card-bg:#f5fcf8;--card-end:#e5f7ec;--card-border:#79c69d;--card-muted:#557463;--card-text:#193d2c}.palette-lavender{--card-bg:#fbf8ff;--card-end:#f0e8ff;--card-border:#b497df;--card-muted:#706384;--card-text:#35284f}.palette-charcoal_gold{--card-bg:#334155;--card-end:#263247;--card-border:#d9a400;--card-muted:#d7deea;--card-text:#fff}.palette-neutral{--card-bg:#fff;--card-end:#fff;--card-border:#cbd5e1;--card-muted:#64748b;--card-text:#172033}';
    }
}
