<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use RuntimeException;

final class CsvReportRenderer
{
    /** @param array<string, mixed> $report */
    public function render(array $report, string $path): void
    {
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create the CSV report.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['record_type', 'dashboard', 'widget', 'metric_or_insight_key', 'date', 'dimension', 'value', 'current_value', 'previous_value', 'absolute_change', 'relative_change_percent', 'percentage_point_change', 'unit', 'formula_version', 'formula', 'source_as_of', 'suppression_code', 'suppression_message', 'activation_state', 'comparison_basis', 'provenance', 'effective_provenance', 'materiality_outcome', 'coverage', 'entity_scope']);
        $scope = json_encode($report['insights']['scope'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $readinessByMetric = array_column($report['insights']['readiness']['metrics'] ?? [], null, 'metric');
        foreach ($report['widgets'] as $item) {
            $widget = $item['definition'];
            $data = $item['data'];
            $readinessMetric = $widget['metric'] === 'current_open_tickets' ? 'historical_open_backlog' : $widget['metric'];
            $activation = $readinessByMetric[$readinessMetric] ?? [];
            $coverage = isset($activation['available_days'], $activation['required_days']) ? sprintf('%d/%d days', (int) $activation['available_days'], (int) $activation['required_days']) : '';
            if (array_key_exists('value', $data)) {
                $this->row($stream, ['metric', $report['dashboard']['name'], $widget['title'], $widget['metric'], $report['to'], '', $data['value'], '', '', '', '', '', '', '', '', $data['as_of'] ?? '', '', '', $activation['activation_state'] ?? 'CURRENT_STATE', $activation['comparison_basis'] ?? 'Current value', $data['provenance'] ?? '', $data['effective_provenance'] ?? '', '', $coverage, $scope]);
                continue;
            }
            foreach ($data['series'] ?? [] as $point) {
                $this->row($stream, [
                    'metric', $report['dashboard']['name'], $widget['title'], $widget['metric'], $point['date'] ?? '',
                    $point['dimension'] ?? '', $point['value'] ?? '', '', '', '', '', '', '', '', '', $data['as_of'] ?? '', '', '', $activation['activation_state'] ?? 'CURRENT_STATE', $activation['comparison_basis'] ?? 'Current value', $data['provenance'] ?? '', $data['effective_provenance'] ?? '', '', $coverage, $scope,
                ]);
            }
        }
        foreach ($report['insights']['insights'] ?? [] as $insight) {
            $calculation = $insight['calculation'] ?? [];
            $this->row($stream, [
                'insight', $report['dashboard']['name'], '', $insight['key'], $report['insights']['cutoff'] ?? $report['to'],
                $insight['contributor']['label'] ?? '', '', $insight['current'], $insight['previous'], $insight['absolute_change'],
                $insight['relative_change_percent'] ?? '', $insight['percentage_point_change'] ?? '', $insight['unit'],
                $calculation['formula_version'] ?? $report['insights']['formula_version'] ?? '', $calculation['formula'] ?? '', $insight['as_of'] ?? '', '', '',
                $insight['activation_state'] ?? '', $insight['comparison_basis'] ?? '', $insight['provenance'] ?? '', $insight['effective_provenance'] ?? '', $calculation['materiality_outcome'] ?? '', json_encode($calculation['coverage'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $scope,
            ]);
        }
        foreach ($report['insights']['observed_movements'] ?? [] as $movement) {
            $this->row($stream, [
                'derived_measure', $report['dashboard']['name'], '', $movement['metric'], $report['insights']['cutoff'] ?? $report['to'],
                '', '', $movement['current'], $movement['baseline'], $movement['absolute_change'], '', '', '', $report['insights']['formula_version'] ?? '', 'latest certified observation - stable monitoring baseline', '', '', '',
                $movement['activation_state'], $movement['comparison_basis'], $movement['provenance'], $movement['effective_provenance'], 'not eligible', '', $scope,
            ]);
        }
        foreach ($report['insights']['suppressed'] ?? [] as $suppressed) {
            $coverage = json_encode($suppressed['coverage'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->row($stream, [
                'derived_measure', $report['dashboard']['name'], '', $suppressed['key'], $report['insights']['cutoff'] ?? $report['to'],
                '', '', $suppressed['current'] ?? '', $suppressed['previous'] ?? '', $suppressed['absolute_change'] ?? '', $suppressed['relative_change_percent'] ?? '', '', '', $suppressed['formula_version'] ?? $report['insights']['formula_version'] ?? '', $suppressed['formula'] ?? '', $suppressed['last_refresh'] ?? '',
                $suppressed['code'], $suppressed['message'], $suppressed['activation_state'] ?? '', $suppressed['comparison_basis'] ?? '', $suppressed['provenance'] ?? '', $suppressed['effective_provenance'] ?? '', $suppressed['materiality_outcome'] ?? 'suppressed', $coverage, $scope,
            ]);
        }
        fclose($stream);
    }

    /** @param resource $stream
     *  @param list<mixed> $values
     */
    private function row($stream, array $values): void
    {
        fputcsv($stream, array_map(static function (mixed $value): string {
            $text = (string) $value;
            return preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
        }, $values));
    }
}
