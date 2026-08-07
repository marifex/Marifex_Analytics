<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex;

use CommonGLPI;

final class DashboardMenu extends CommonGLPI
{
    public static function getMenuName($nb = 0): string
    {
        return __('Analytics', 'marifex');
    }

    public static function getMenuContent(): array|false
    {
        if (!Profile::canView()) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page' => '/plugins/marifex/Dashboard',
            'icon' => 'ti ti-chart-histogram',
            'links' => [
                'search' => '/plugins/marifex/Dashboard',
            ],
        ];
    }
}

