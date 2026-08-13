<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class ActivationEvaluator
{
    private const SUPPORTED_HORIZONS = [7, 30, 90, 180, 365];

    /**
     * @param list<string> $completedDates Certified observation dates for the exact governed scope and grain.
     */
    public function evaluate(
        DateTimeImmutable $cutoff,
        int $horizon,
        array $completedDates,
        string $sourceState,
        bool $currentEvidenceAvailable,
        ProvenanceEvidence $provenance,
        ?DateTimeImmutable $monitoringBaselineAt = null,
        bool $monitoringBaselineEvidenceAvailable = false,
        bool $comparisonBoundaryEvidenceComplete = true,
    ): ActivationDecision {
        if (!in_array($horizon, self::SUPPORTED_HORIZONS, true)) {
            throw new InvalidArgumentException('Unsupported analytical horizon.');
        }

        $required = 2 * $horizon;
        $available = $this->consecutiveDaysEndingAt($completedDates, $cutoff, $required);
        if (!$provenance->isEligibleForCertifiedUse()) {
            return new ActivationDecision(null, '', $available, $required, 'UNAVAILABLE_SOURCE', 'The available evidence is not eligible for certified analytical use.');
        }
        if ($sourceState !== 'current' || !$currentEvidenceAvailable) {
            $code = match ($sourceState) {
                'stale' => 'STALE_SOURCE',
                'unavailable' => 'UNAVAILABLE_SOURCE',
                default => 'MISSING_SOURCE',
            };
            return new ActivationDecision(null, '', $available, $required, $code, 'A current authorized certified value or completed observation is unavailable.');
        }

        if ($available >= $required && $comparisonBoundaryEvidenceComplete) {
            return $this->decision(ActivationState::CERTIFIED_PERIOD_COMPARISON, $horizon, $available, $required);
        }
        if ($available >= $horizon) {
            return $this->decision(ActivationState::COMPARABLE_WINDOW, $horizon, $available, $required);
        }
        if ($this->hasLaterObservation($completedDates, $monitoringBaselineAt, $cutoff)) {
            if ($monitoringBaselineEvidenceAvailable) {
                return $this->decision(ActivationState::OBSERVED_MOVEMENT, $horizon, $available, $required);
            }
            return new ActivationDecision(
                ActivationState::CURRENT_STATE,
                ActivationState::CURRENT_STATE->comparisonBasis($horizon),
                $available,
                $required,
                'INSUFFICIENT_HISTORY',
                'The stable monitoring baseline evidence is unavailable; monitoring movement is suppressed.',
            );
        }

        return $this->decision(ActivationState::CURRENT_STATE, $horizon, $available, $required);
    }

    private function decision(ActivationState $state, int $horizon, int $available, int $required): ActivationDecision
    {
        return new ActivationDecision($state, $state->comparisonBasis($horizon), $available, $required);
    }

    /** @param list<string> $completedDates */
    private function consecutiveDaysEndingAt(array $completedDates, DateTimeImmutable $cutoff, int $limit): int
    {
        $available = array_fill_keys(array_unique($completedDates), true);
        $count = 0;
        for ($date = $cutoff; $count < $limit; $date = $date->sub(new DateInterval('P1D'))) {
            if (!isset($available[$date->format('Y-m-d')])) {
                break;
            }
            ++$count;
        }
        return $count;
    }

    /** @param list<string> $completedDates */
    private function hasLaterObservation(array $completedDates, ?DateTimeImmutable $baselineAt, DateTimeImmutable $cutoff): bool
    {
        if ($baselineAt === null || $baselineAt >= $cutoff) {
            return false;
        }
        foreach ($completedDates as $date) {
            $observation = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($observation !== false && $observation > $baselineAt && $observation <= $cutoff) {
                return true;
            }
        }
        return false;
    }
}
