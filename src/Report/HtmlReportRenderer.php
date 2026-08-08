<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

final class HtmlReportRenderer
{
    private const PALETTES = [
        'cream_gold' => ['#d99a00', '#f2bd31', '#ffda68', '#b87900', '#8d6411', '#f7c95f', '#c88c14', '#ffe59a', '#aa7410', '#6f5220'],
        'ocean' => ['#1479c9', '#26a6d1', '#49c5b6', '#075d9a', '#5a8dee', '#1b91a8', '#70b7e6', '#087f8c', '#83d7cb', '#2c64ad'],
        'mint' => ['#176b43', '#248653', '#2f9e66', '#42b578', '#62c58f', '#83d5a7', '#a4e3bf', '#0f5936', '#357a55', '#55a978'],
        'lavender' => ['#7656b5', '#936bd0', '#b07ee2', '#5e46a1', '#c18ce8', '#8157bf', '#a472d4', '#d0a2ef', '#684ca7', '#9b7ac5'],
        'charcoal_gold' => ['#f2bd31', '#ffe08a', '#d99a00', '#fff0b8', '#bd7f00', '#f7cf62', '#e6aa15', '#fff5d2', '#c78d16', '#f4c34a'],
        'neutral' => ['#4361ee', '#a7cf24', '#50597b', '#f05d7b', '#7656b5', '#34a853', '#f5bd00', '#009bb8', '#f47b3d', '#586174'],
        'classic_blue' => ['#1d4ed8', '#2563eb', '#3b82f6'],
        'teal_green' => ['#047857', '#10b981', '#34d399'],
        'deep_purple' => ['#5b21b6', '#8b5cf6', '#a78bfa'],
        'warm_amber' => ['#b45309', '#fbbf24', '#fde68a'],
        'coral_red' => ['#b91c1c', '#ef4444', '#fca5a5'],
        'sky_blue' => ['#2563eb', '#60a5fa', '#93c5fd'],
        'bright_orange' => ['#c2410c', '#f97316', '#fb923c'],
        'rose_pink' => ['#be185d', '#f472b6', '#fb7185'],
        'forest_green' => ['#065f46', '#34d399', '#6ee7b7'],
        'slate_gray' => ['#4b5563', '#9ca3af', '#cbd5e1'],
    ];

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
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . $this->css() . $this->extendedPaletteCss() . '</style></head><body>'
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
        $palette = isset(self::PALETTES[$widget['palette'] ?? '']) ? (string) $widget['palette'] : 'cream_gold';
        $colors = self::PALETTES[$palette];
        $body = match ($widget['type']) {
            'kpi' => '<div class="kpi">' . $this->e($this->kpi($widget['metric'], $data)) . '</div><p class="context">Current certified value</p>',
            'line' => $this->line($data['series'] ?? [], $colors[0]),
            'bar' => $this->bars($data['series'] ?? [], $colors[0]),
            'donut' => $this->donut($data['series'] ?? [], $colors),
            'table' => $this->table($data['series'] ?? []),
            default => '',
        };
        $class = ($widget['type'] === 'kpi' ? 'card card-kpi ' : 'card ') . 'palette-' . $palette;
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
    private function line(array $series, string $color): string
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
        return '<svg class="chart" viewBox="0 0 640 180" role="img"><line x1="28" y1="155" x2="612" y2="155" class="axis"/><polyline points="' . implode(' ', $points) . '" class="line" style="stroke:' . $color . '"/></svg>'
            . '<div class="chart-labels"><span>' . $this->e((string) $series[0]['date']) . '</span><span>' . $this->e((string) $series[array_key_last($series)]['date']) . '</span></div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function bars(array $series, string $color): string
    {
        $latest = $this->latestDimensions($series);
        if ($latest === []) return '<p class="empty">No data in this range</p>';
        $max = max(array_column($latest, 'value')) ?: 1;
        $html = '<div class="bars">';
        foreach (array_slice($latest, 0, 10) as $point) {
            $width = max(1, ((float) $point['value'] / $max) * 100);
            $html .= '<div class="bar-row"><span>' . $this->e((string) $point['dimension']) . '</span><i><b style="width:' . round($width, 1) . '%;background:' . $color . '"></b></i><strong>' . number_format((float) $point['value']) . '</strong></div>';
        }
        return $html . '</div>';
    }

    /** @param list<array<string, mixed>> $series */
    private function donut(array $series, array $colors): string
    {
        $latest = array_slice($this->latestDimensions($series), 0, 10);
        if ($latest === []) return '<p class="empty">No data in this range</p>';
        $total = array_sum(array_column($latest, 'value')) ?: 1; $cursor = 0; $stops = []; $legend = '';
        foreach ($latest as $index => $point) {
            $start = $cursor; $cursor += ((float) $point['value'] / $total) * 100; $color = $colors[$index % count($colors)];
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

    private function css(): string
    {
        return '@page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{margin:0;color:#263247;font-family:Arial,sans-serif;background:#fff}header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #e7ebf2;padding:2px 2px 12px;margin-bottom:12px}.brand{margin:0 0 5px;color:#f2b93b;font-size:10px;font-weight:800;letter-spacing:1.3px}h1{margin:0;font-size:24px}.sub{margin:4px 0 0;color:#687386;font-size:11px}.meta{text-align:right;font-size:11px}.meta strong,.meta span{display:block;margin-bottom:4px}main{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.card{--card-bg:#fff;--card-end:#fff;--card-border:#dfe4ec;--card-muted:#6b7688;--card-text:#263247;background:linear-gradient(135deg,var(--card-bg),var(--card-end));border:1px solid var(--card-border);border-radius:10px;color:var(--card-text);padding:11px;min-height:225px;break-inside:avoid;box-shadow:0 2px 8px rgba(38,50,71,.05)}.palette-cream_gold{--card-bg:#fffdf4;--card-end:#fff0b8;--card-border:#ecd27c;--card-muted:#776943;--card-text:#3b3321}.palette-ocean{--card-bg:#f5fbff;--card-end:#dcefff;--card-border:#9bc9eb;--card-muted:#55728d;--card-text:#17334f}.palette-mint{--card-bg:#f5fcf8;--card-end:#def5e7;--card-border:#9dd6b7;--card-muted:#557463;--card-text:#193d2c}.palette-lavender{--card-bg:#fbf8ff;--card-end:#eadfff;--card-border:#c7afe9;--card-muted:#706384;--card-text:#35284f}.palette-charcoal_gold{--card-bg:#263247;--card-end:#263247;--card-border:#58657a;--card-muted:#d7deea;--card-text:#fff}.palette-neutral{--card-bg:#fff;--card-end:#fff;--card-border:#dfe4ec;--card-muted:#6b7688;--card-text:#263247}.card-kpi{min-height:128px}.card h2{margin:0 0 10px;padding-bottom:8px;border-bottom:1px solid var(--card-border);font-size:14px}.kpi{font-size:38px;font-weight:800;margin-top:16px}.context,.empty{color:var(--card-muted);font-size:10px}.chart{width:100%;height:150px}.axis{stroke:var(--card-border);stroke-width:1}.line{fill:none;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}.chart-labels{display:flex;justify-content:space-between;color:var(--card-muted);font-size:9px}.bars{display:grid;gap:7px}.bar-row{display:grid;grid-template-columns:130px 1fr 45px;gap:8px;align-items:center;font-size:9px}.bar-row i{height:10px;background:rgba(127,127,127,.18);border-radius:5px;overflow:hidden}.bar-row b{display:block;height:100%;border-radius:5px}.bar-row strong{text-align:right}.donut-wrap{display:grid;grid-template-columns:180px 1fr;gap:20px;align-items:center}.donut{width:150px;height:150px;border-radius:50%;position:relative;display:grid;place-items:center}.donut:after{content:"";position:absolute;inset:33px;background:var(--card-bg);border-radius:50%}.donut span{z-index:1;font-size:17px;font-weight:800}.legend{display:grid;gap:5px}.legend div{display:grid;grid-template-columns:9px 1fr 38px;gap:6px;align-items:center;font-size:9px}.legend i{width:9px;height:9px;border-radius:2px}.legend strong{text-align:right}table{width:100%;border-collapse:collapse;font-size:9px}th,td{padding:5px;border-bottom:1px solid var(--card-border);text-align:left}th:last-child,td:last-child{text-align:right}footer{display:flex;justify-content:space-between;border-top:1px solid #e7ebf2;margin-top:12px;padding-top:7px;color:#727d8e;font-size:8px}';
    }
}
