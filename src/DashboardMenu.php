<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
            'page' => '/front/central.php?forcetab=GlpiPlugin%5CMarifex%5CHomeDashboardTab%241',
            'icon' => 'ti ti-chart-histogram',
            'links' => [
                'search' => '/front/central.php?forcetab=GlpiPlugin%5CMarifex%5CHomeDashboardTab%241',
            ],
        ];
    }
}

