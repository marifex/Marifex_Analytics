<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

use DomainException;

final readonly class ProvenanceEvidence
{
    private function __construct(
        public Provenance $provenance,
        public Provenance $effectiveProvenance,
    ) {
    }

    public static function observed(): self
    {
        return new self(Provenance::OBSERVED, Provenance::OBSERVED);
    }

    public static function certifiedBootstrap(): self
    {
        return new self(Provenance::CERTIFIED_BOOTSTRAP, Provenance::CERTIFIED_BOOTSTRAP);
    }

    public static function uncertifiedReconstruction(): self
    {
        return new self(Provenance::UNCERTIFIED_RECONSTRUCTION, Provenance::UNCERTIFIED_RECONSTRUCTION);
    }

    public static function derived(self ...$inputs): self
    {
        if ($inputs === []) {
            throw new DomainException('A derived analytical value requires at least one input.');
        }

        $effective = Provenance::OBSERVED;
        foreach ($inputs as $input) {
            if (!$input->isEligibleForCertifiedUse()) {
                throw new DomainException('UNCERTIFIED_RECONSTRUCTION cannot feed a certified analytical calculation.');
            }
            if ($input->effectiveProvenance === Provenance::CERTIFIED_BOOTSTRAP) {
                $effective = Provenance::CERTIFIED_BOOTSTRAP;
            }
        }

        return new self(Provenance::DERIVED, $effective);
    }

    public function isEligibleForCertifiedUse(): bool
    {
        return $this->provenance !== Provenance::UNCERTIFIED_RECONSTRUCTION
            && $this->effectiveProvenance !== Provenance::UNCERTIFIED_RECONSTRUCTION;
    }

    /** @return array{provenance:string,provenance_label:string,effective_provenance:string,effective_provenance_label:string} */
    public function toArray(): array
    {
        $effectiveLabel = $this->effectiveProvenance->clientLabel();
        if ($this->provenance === Provenance::DERIVED) {
            $effectiveLabel = $this->effectiveProvenance === Provenance::OBSERVED
                ? 'Derived from observed data'
                : 'Derived from certified historical reconstruction';
        }

        return [
            'provenance' => $this->provenance->value,
            'provenance_label' => $this->provenance->clientLabel(),
            'effective_provenance' => $this->effectiveProvenance->value,
            'effective_provenance_label' => $effectiveLabel,
        ];
    }
}
