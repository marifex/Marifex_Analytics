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
        fputcsv($stream, ['record_type', 'dashboard', 'widget', 'metric_or_insight_key', 'date', 'dimension', 'value', 'current_value', 'previous_value', 'absolute_change', 'relative_change_percent', 'percentage_point_change', 'unit', 'formula_version', 'formula', 'source_as_of', 'suppression_code', 'suppression_message']);
        foreach ($report['widgets'] as $item) {
            $widget = $item['definition'];
            $data = $item['data'];
            if (array_key_exists('value', $data)) {
                $this->row($stream, ['metric', $report['dashboard']['name'], $widget['title'], $widget['metric'], $report['to'], '', $data['value'], '', '', '', '', '', '', '', '', $data['as_of'] ?? '', '', '']);
                continue;
            }
            foreach ($data['series'] ?? [] as $point) {
                $this->row($stream, [
                    'metric', $report['dashboard']['name'], $widget['title'], $widget['metric'], $point['date'] ?? '',
                    $point['dimension'] ?? '', $point['value'] ?? '', '', '', '', '', '', '', '', '', $data['as_of'] ?? '', '', '',
                ]);
            }
        }
        foreach ($report['insights']['insights'] ?? [] as $insight) {
            $calculation = $insight['calculation'] ?? [];
            $this->row($stream, [
                'insight', $report['dashboard']['name'], '', $insight['key'], $report['insights']['cutoff'] ?? $report['to'],
                $insight['contributor']['label'] ?? '', '', $insight['current'], $insight['previous'], $insight['absolute_change'],
                $insight['relative_change_percent'] ?? '', $insight['percentage_point_change'] ?? '', $insight['unit'],
                $report['insights']['formula_version'] ?? '', $calculation['formula'] ?? '', $insight['as_of'] ?? '', '', '',
            ]);
        }
        foreach ($report['insights']['suppressed'] ?? [] as $suppressed) {
            $this->row($stream, [
                'derived_measure', $report['dashboard']['name'], '', $suppressed['key'], $report['insights']['cutoff'] ?? $report['to'],
                '', '', '', '', '', '', '', '', $report['insights']['formula_version'] ?? '', '', '',
                $suppressed['code'], $suppressed['message'],
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
