<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

enum Provenance: string
{
    case OBSERVED = 'OBSERVED';
    case CERTIFIED_BOOTSTRAP = 'CERTIFIED_BOOTSTRAP';
    case DERIVED = 'DERIVED';
    case UNCERTIFIED_RECONSTRUCTION = 'UNCERTIFIED_RECONSTRUCTION';

    public function clientLabel(): string
    {
        return match ($this) {
            self::OBSERVED => 'Observed',
            self::CERTIFIED_BOOTSTRAP => 'Certified historical reconstruction',
            self::DERIVED => 'Derived',
            self::UNCERTIFIED_RECONSTRUCTION => 'Uncertified reconstruction',
        };
    }

    public function isCertifiedSource(): bool
    {
        return $this === self::OBSERVED || $this === self::CERTIFIED_BOOTSTRAP;
    }
}
