<?php

namespace App\Services\DevEngine;

/**
 * A deterministic (no AI call) confidence score for a generated plan —
 * High/Medium/Low, shown in the UI so the user knows how much to trust a
 * plan before approving it. Every factor is normalized to a 0..1 "goodness"
 * value and averaged with fixed weights; a factor whose signal isn't
 * available is passed as null and excluded from the average entirely,
 * rather than penalizing every plan for a check that hasn't run yet.
 *
 * `contextMatched` and `existingFileRatio` are computed today, in
 * FeatureAgentService::generatePlan(). `indexFreshness` (Project Brain
 * Freshness), `duplicateRisk` (Duplicate Feature Detection), and
 * `architectureConsistent` (Architecture Validation) are wired in by those
 * later subsystems and stay null until then.
 */
class ConfidenceScorer
{
    private const WEIGHTS = [
        'contextMatched' => 2,
        'existingFileRatio' => 2,
        'indexFreshness' => 1,
        'duplicateRisk' => 1,
        'architectureConsistent' => 1,
    ];

    /**
     * @param  array{contextMatched?: bool|null, existingFileRatio?: float|null, indexFreshness?: string|null, duplicateRisk?: bool|null, architectureConsistent?: bool|null}  $factors
     * @return array{level: string, score: float|null}
     */
    public function score(array $factors): array
    {
        $normalized = [
            'contextMatched' => $this->boolToGoodness($factors['contextMatched'] ?? null),
            'existingFileRatio' => $factors['existingFileRatio'] ?? null,
            'indexFreshness' => match ($factors['indexFreshness'] ?? null) {
                'fresh' => 1.0,
                'stale' => 0.0,
                default => null,
            },
            // duplicateRisk is "true = a likely duplicate was found", i.e.
            // bad news for confidence — invert it before treating true as
            // good the way every other factor here does.
            'duplicateRisk' => $this->boolToGoodness(
                array_key_exists('duplicateRisk', $factors) && $factors['duplicateRisk'] !== null
                    ? ! $factors['duplicateRisk']
                    : null,
            ),
            'architectureConsistent' => $this->boolToGoodness($factors['architectureConsistent'] ?? null),
        ];

        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($normalized as $key => $value) {
            if ($value === null) {
                continue;
            }
            $weightedSum += $value * self::WEIGHTS[$key];
            $totalWeight += self::WEIGHTS[$key];
        }

        if ($totalWeight === 0.0) {
            // No signal was available at all — neutral, not a penalty.
            return ['level' => 'medium', 'score' => null];
        }

        $ratio = $weightedSum / $totalWeight;
        $level = match (true) {
            $ratio >= 0.75 => 'high',
            $ratio >= 0.4 => 'medium',
            default => 'low',
        };

        return ['level' => $level, 'score' => round($ratio, 2)];
    }

    private function boolToGoodness(?bool $value): ?float
    {
        return $value === null ? null : ($value ? 1.0 : 0.0);
    }
}
