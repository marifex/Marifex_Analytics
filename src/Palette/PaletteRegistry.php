<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Palette;

final class PaletteRegistry
{
    public const SURFACE_TO_CHART = [
        'cream_gold' => 'chart_cream_gold', 'ocean' => 'chart_ocean', 'mint' => 'chart_mint',
        'lavender' => 'chart_lavender', 'charcoal_gold' => 'chart_charcoal_gold', 'neutral' => 'chart_neutral',
        'classic_blue' => 'chart_classic_blue', 'teal_green' => 'chart_teal_green', 'deep_purple' => 'chart_deep_purple',
        'warm_amber' => 'chart_warm_amber', 'coral_red' => 'chart_coral_red', 'sky_blue' => 'chart_sky_blue',
        'bright_orange' => 'chart_bright_orange', 'rose_pink' => 'chart_rose_pink', 'forest_green' => 'chart_forest_green',
        'slate_gray' => 'chart_slate_gray',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function builtIns(): array
    {
        $sets = [
            'cream_gold' => ['Cream Gold', ['#D99A00','#F2BD31','#FFDA68','#B87900','#8D6411','#F7C95F','#C88C14','#FFE59A','#AA7410','#6F5220','#F3AF17','#D4B261']],
            'ocean' => ['Ocean Blue', ['#1479C9','#26A6D1','#49C5B6','#075D9A','#5A8DEE','#1B91A8','#70B7E6','#087F8C','#83D7CB','#2C64AD','#4A9FD8','#0C718F']],
            'mint' => ['Mint', ['#176B43','#248653','#2F9E66','#42B578','#62C58F','#83D5A7','#A4E3BF','#0F5936','#357A55','#55A978','#78BF95','#9BD4B2']],
            'lavender' => ['Lavender', ['#7656B5','#936BD0','#B07EE2','#5E46A1','#C18CE8','#8157BF','#A472D4','#D0A2EF','#684CA7','#9B7AC5','#B891DB','#573C90']],
            'charcoal_gold' => ['Charcoal Gold', ['#F2BD31','#FFE08A','#D99A00','#FFF0B8','#BD7F00','#F7CF62','#E6AA15','#FFF5D2','#C78D16','#F4C34A','#AA7613','#E9D49B']],
            'neutral' => ['Classic White', ['#4361EE','#A7CF24','#50597B','#F05D7B','#7656B5','#34A853','#F5BD00','#009BB8','#F47B3D','#586174','#9A60B4','#EA7CCC']],
            'classic_blue' => ['Classic Blue', ['#1D4ED8','#2563EB','#3B82F6','#60A5FA','#0EA5E9','#64748B']],
            'teal_green' => ['Teal Green', ['#047857','#10B981','#34D399','#0F766E','#14B8A6','#64748B']],
            'deep_purple' => ['Deep Purple', ['#5B21B6','#8B5CF6','#A78BFA','#7E22CE','#C084FC','#64748B']],
            'warm_amber' => ['Warm Amber', ['#B45309','#F59E0B','#FBBF24','#D97706','#FCD34D','#78716C']],
            'coral_red' => ['Coral Red', ['#B91C1C','#EF4444','#F87171','#BE123C','#FB7185','#64748B']],
            'sky_blue' => ['Sky Blue', ['#2563EB','#60A5FA','#93C5FD','#0284C7','#38BDF8','#64748B']],
            'bright_orange' => ['Bright Orange', ['#C2410C','#F97316','#FB923C','#EA580C','#FDBA74','#64748B']],
            'rose_pink' => ['Rose Pink', ['#BE185D','#F472B6','#FB7185','#DB2777','#FDA4AF','#64748B']],
            'forest_green' => ['Forest Green', ['#065F46','#34D399','#6EE7B7','#15803D','#4ADE80','#64748B']],
            'slate_gray' => ['Slate Gray', ['#4B5563','#9CA3AF','#CBD5E1','#334155','#64748B','#94A3B8']],
        ];
        $result = [];
        foreach ($sets as $key => [$label, $colors]) {
            $result['chart_' . $key] = [
                'id' => 'chart_' . $key, 'name' => $label, 'type' => 'categorical', 'colors' => $colors,
                'areaOpacity' => 0.25, 'revision' => 1, 'builtIn' => true, 'inherited' => false, 'readOnly' => true,
            ];
        }
        return $result;
    }

    public static function requiredSlots(string $widgetType, string $metric): int
    {
        if ($widgetType === 'donut') return 6;
        if ($metric === 'created_vs_resolved_tickets') return 2;
        return in_array($widgetType, ['line', 'bar'], true) ? 1 : 0;
    }
}
