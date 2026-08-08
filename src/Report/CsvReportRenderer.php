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
        fputcsv($stream, ['Dashboard', 'Widget', 'Metric', 'Date', 'Dimension', 'Value']);
        foreach ($report['widgets'] as $item) {
            $widget = $item['definition'];
            $data = $item['data'];
            if (array_key_exists('value', $data)) {
                $this->row($stream, [$report['dashboard']['name'], $widget['title'], $widget['metric'], $report['to'], '', $data['value']]);
                continue;
            }
            foreach ($data['series'] ?? [] as $point) {
                $this->row($stream, [
                    $report['dashboard']['name'],
                    $widget['title'],
                    $widget['metric'],
                    $point['date'] ?? '',
                    $point['dimension'] ?? '',
                    $point['value'] ?? '',
                ]);
            }
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
