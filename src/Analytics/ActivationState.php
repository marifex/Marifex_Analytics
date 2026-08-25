<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

enum ActivationState: string
{
    case CURRENT_STATE = 'CURRENT_STATE';
    case OBSERVED_MOVEMENT = 'OBSERVED_MOVEMENT';
    case COMPARABLE_WINDOW = 'COMPARABLE_WINDOW';
    case CERTIFIED_PERIOD_COMPARISON = 'CERTIFIED_PERIOD_COMPARISON';

    public function comparisonBasis(int $horizon): string
    {
        return match ($this) {
            self::CURRENT_STATE => 'Current value',
            self::OBSERVED_MOVEMENT => 'Since monitoring began',
            self::COMPARABLE_WINDOW => sprintf('Current %d-day window', $horizon),
            self::CERTIFIED_PERIOD_COMPARISON => sprintf('vs prior %d days', $horizon),
        };
    }
}
