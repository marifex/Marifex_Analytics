<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

final class HtmlReportRenderer
{
    private const COLORS = ['#4361ee', '#a7cf24', '#50597b', '#f05d7b', '#7656b5', '#34a853', '#f5bd00', '#009bb8', '#f47b3d', '#586174'];

    /** @param array<string, mixed> $report */
    public function render(array $report): string
    {
        $name = $this->e((string) $report['dashboard']['name']);
        $cards = '';
        foreach ($report['widgets'] as $item) {
            $cards .= $this->widget($item['definition'], $item['data']);
        }
        $range = $this->e(sprintf('%s to %s', $report['from'], $report['to']));
        $generated = $this->e((new \DateTimeImmutable($report['generated_at']))->format('Y-m-d H:i T'));
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . $this->css() . '</style></head><body>'
            . '<header><div><p class="brand">MARIFEX ADVANCED ANALYTICS</p><h1>' . $name . '</h1><p class="sub">Static governed dashboard report</p></div>'
            . '<div class="meta"><strong>' . $range . '</strong><span>Entity #' . (int) $report['entities_id'] . '</span></div></header>'
            . '<main>' . $cards . '</main><footer><span>Generated ' . $generated . '</span><span>MarifeX for GLPI</span></footer></body></html>';
    }

    /** @param array<string, mixed> $widget
     *  @param array<string, mixed> $data
     */
    private function widget(array $widget, array $data): string
    {
        $title = $this->e((string) $widget['title']);
        $body = match ($widget['type']) {
            'kpi' => '<div class="kpi">' . $this->e($this->kpi($widget['metric'], $data)) . '</div><p class="context">Current certified value</p>',
            'line' => $this->line($data['series'] ?? []),
            'bar' => $this->bars($data['series'] ?? []),
            'donut' => $this->donut($data['series'] ?? []),
            'table' => $this->table($data['series'] ?? []),
            default => '',
        };
        $class = $widget['type'] === 'kpi' ? 'card card-kpi' : 'card';
        return '<section class="' . $class . '"><h2>' . $title . '</h2>' . $body . '</section>';
    }

    /** @param array<string, mixed> $data */
    private function kpi(string $metric, array $data): string
    {
        $series = $data['series'] ?? [];
        $value = $data['value'] ?? ($series === [] ? null : $series[array_key_last($series)]['value']);
        if ($value === null) {
            return 'Not available';
        }
        if ($metric === 'average_open_ticket_age') {
            return number_format(((float) $value) / 86400, 1) . ' days';
        }
        if ($metric === 'software_license_compliance_rate') {
            return number_format((float) $value, 1) . '%';
        }
        return number_format((float) $value, is_float($value) ? 1 : 0);
    }

    /** @param list<array<string, mixed>> $series */
    private function line(array $series): string
    {
        if ($series === []) {
            return '<p class="empty">No data in this range</p>';
        }
        $values = array_map(static fn(array $point): float => (float) $point['value'], $series);
        $min = min($values); $max = max($values); $span = max(1.0, $max - $min); $count = max(1, count($values) - 1);
        $points = [];
        foreach ($values as $index => $value) {
            $x = 28 + (584 * $index / $count);
            $y = 155 - (125 * ($value - $min) / $span);
            $points[] = sprintf('%.1f,%.1f', $x, $y);
        }
        return '<svg class="chart" viewBox="0 0 640 180" role="img"><line x1="28" y1="155" x2="612" y2="155" class="axis"/><polyline points="' . implode(' ', $points) . '" class="line"/></svg>'
            . '<div class="chart-labels"><span>' . $this->e((string) $series[0]['date']) . '</span><span>' . $this->e((string) $series[array_key_last($series)]['date']) . '</span></div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function bars(array $series): string
    {
        $latest = $this->latestDimensions($series);
        if ($latest === []) return '<p class="empty">No data in this range</p>';
        $max = max(array_column($latest, 'value')) ?: 1;
        $html = '<div class="bars">';
        foreach (array_slice($latest, 0, 10) as $point) {
            $width = max(1, ((float) $point['value'] / $max) * 100);
            $html .= '<div class="bar-row"><span>' . $this->e((string) $point['dimension']) . '</span><i><b style="width:' . round($width, 1) . '%"></b></i><strong>' . number_format((float) $point['value']) . '</strong></div>';
        }
        return $html . '</div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function donut(array $series): string
    {
        $latest = array_slice($this->latestDimensions($series), 0, 10);
        if ($latest === []) return '<p class="empty">No data in this range</p>';
        $total = array_sum(array_column($latest, 'value')) ?: 1; $cursor = 0; $stops = []; $legend = '';
        foreach ($latest as $index => $point) {
            $start = $cursor; $cursor += ((float) $point['value'] / $total) * 100; $color = self::COLORS[$index % count(self::COLORS)];
            $stops[] = sprintf('%s %.2f%% %.2f%%', $color, $start, $cursor);
            $legend .= '<div><i style="background:' . $color . '"></i><span>' . $this->e((string) $point['dimension']) . '</span><strong>' . number_format((float) $point['value']) . '</strong></div>';
        }
        return '<div class="donut-wrap"><div class="donut" style="background:conic-gradient(' . implode(',', $stops) . ')"><span>' . number_format($total) . '</span></div><div class="legend">' . $legend . '</div></div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function table(array $series): string
    {
        $latest = $this->latestDimensions($series);
        if ($latest === []) return '<p class="empty">No data in this range</p>';
        $html = '<table><thead><tr><th>Dimension</th><th>Value</th></tr></thead><tbody>';
        foreach (array_slice($latest, 0, 12) as $point) {
            $html .= '<tr><td>' . $this->e((string) $point['dimension']) . '</td><td>' . number_format((float) $point['value']) . '</td></tr>';
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

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private function css(): string
    {
        return '@page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{margin:0;color:#263247;font-family:Arial,sans-serif;background:#fff}header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #e7ebf2;padding:2px 2px 12px;margin-bottom:12px}.brand{margin:0 0 5px;color:#f2b93b;font-size:10px;font-weight:800;letter-spacing:1.3px}h1{margin:0;font-size:24px}.sub{margin:4px 0 0;color:#687386;font-size:11px}.meta{text-align:right;font-size:11px}.meta strong,.meta span{display:block;margin-bottom:4px}main{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.card{border:1px solid #dfe4ec;border-radius:10px;padding:11px;min-height:225px;break-inside:avoid;box-shadow:0 2px 8px rgba(38,50,71,.05)}.card-kpi{min-height:128px}.card h2{margin:0 0 10px;padding-bottom:8px;border-bottom:1px solid #edf0f5;font-size:14px}.kpi{font-size:38px;font-weight:800;margin-top:16px}.context,.empty{color:#6b7688;font-size:10px}.chart{width:100%;height:150px}.axis{stroke:#ccd3de;stroke-width:1}.line{fill:none;stroke:#4361ee;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}.chart-labels{display:flex;justify-content:space-between;color:#6b7688;font-size:9px}.bars{display:grid;gap:7px}.bar-row{display:grid;grid-template-columns:130px 1fr 45px;gap:8px;align-items:center;font-size:9px}.bar-row i{height:10px;background:#edf1f6;border-radius:5px;overflow:hidden}.bar-row b{display:block;height:100%;background:#4361ee;border-radius:5px}.bar-row strong{text-align:right}.donut-wrap{display:grid;grid-template-columns:180px 1fr;gap:20px;align-items:center}.donut{width:150px;height:150px;border-radius:50%;position:relative;display:grid;place-items:center}.donut:after{content:"";position:absolute;inset:33px;background:#fff;border-radius:50%}.donut span{z-index:1;font-size:17px;font-weight:800}.legend{display:grid;gap:5px}.legend div{display:grid;grid-template-columns:9px 1fr 38px;gap:6px;align-items:center;font-size:9px}.legend i{width:9px;height:9px;border-radius:2px}.legend strong{text-align:right}table{width:100%;border-collapse:collapse;font-size:9px}th,td{padding:5px;border-bottom:1px solid #edf0f5;text-align:left}th:last-child,td:last-child{text-align:right}footer{display:flex;justify-content:space-between;border-top:1px solid #e7ebf2;margin-top:12px;padding-top:7px;color:#727d8e;font-size:8px}';
    }
}
