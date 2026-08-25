<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

final readonly class ActivationDecision
{
    public function __construct(
        public ?ActivationState $state,
        public string $comparisonBasis,
        public int $availableDays,
        public int $requiredDays,
        public ?string $suppressionCode = null,
        public ?string $suppressionReason = null,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'activation_state' => $this->state?->value,
            'comparison_basis' => $this->comparisonBasis,
            'available_days' => $this->availableDays,
            'required_days' => $this->requiredDays,
            'suppression_code' => $this->suppressionCode,
            'suppression_reason' => $this->suppressionReason,
        ];
    }
}
