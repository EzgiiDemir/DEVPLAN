<?php

namespace Tests\Unit;

use App\Services\DevEngine\ConfidenceScorer;
use Tests\TestCase;

class ConfidenceScorerTest extends TestCase
{
    public function test_all_signals_good_scores_high(): void
    {
        $scorer = new ConfidenceScorer;

        $result = $scorer->score([
            'contextMatched' => true,
            'existingFileRatio' => 1.0,
            'indexFreshness' => 'fresh',
            'duplicateRisk' => false,
            'architectureConsistent' => true,
        ]);

        $this->assertSame('high', $result['level']);
        $this->assertSame(1.0, $result['score']);
    }

    public function test_all_signals_bad_scores_low(): void
    {
        $scorer = new ConfidenceScorer;

        $result = $scorer->score([
            'contextMatched' => false,
            'existingFileRatio' => 0.0,
            'indexFreshness' => 'stale',
            'duplicateRisk' => true,
            'architectureConsistent' => false,
        ]);

        $this->assertSame('low', $result['level']);
        $this->assertSame(0.0, $result['score']);
    }

    public function test_no_signals_available_is_neutral_medium_not_a_penalty(): void
    {
        $scorer = new ConfidenceScorer;

        $result = $scorer->score([
            'contextMatched' => null,
            'existingFileRatio' => null,
            'indexFreshness' => null,
            'duplicateRisk' => null,
            'architectureConsistent' => null,
        ]);

        $this->assertSame('medium', $result['level']);
        $this->assertNull($result['score']);
    }

    public function test_missing_keys_are_treated_the_same_as_null(): void
    {
        $scorer = new ConfidenceScorer;

        $result = $scorer->score([]);

        $this->assertSame('medium', $result['level']);
        $this->assertNull($result['score']);
    }

    /**
     * The two signals real today (contextMatched, existingFileRatio) are
     * weighted 2 each — a genuine keyword match plus every claimed
     * modify/delete target actually existing should be enough for "high" on
     * its own, without needing the three not-yet-built signals.
     */
    public function test_only_the_two_currently_available_signals_can_still_reach_high(): void
    {
        $scorer = new ConfidenceScorer;

        $result = $scorer->score([
            'contextMatched' => true,
            'existingFileRatio' => 1.0,
        ]);

        $this->assertSame('high', $result['level']);
    }

    public function test_a_hallucinated_plan_with_no_context_match_and_no_real_files_scores_low(): void
    {
        $scorer = new ConfidenceScorer;

        $result = $scorer->score([
            'contextMatched' => false,
            'existingFileRatio' => 0.0,
        ]);

        $this->assertSame('low', $result['level']);
    }

    public function test_a_partial_existing_file_ratio_lands_in_the_middle(): void
    {
        $scorer = new ConfidenceScorer;

        $result = $scorer->score([
            'contextMatched' => true,
            'existingFileRatio' => 0.0,
        ]);

        $this->assertSame('medium', $result['level']);
        $this->assertSame(0.5, $result['score']);
    }

    public function test_duplicate_risk_true_is_penalized_not_rewarded(): void
    {
        $scorer = new ConfidenceScorer;

        $withRisk = $scorer->score(['duplicateRisk' => true]);
        $withoutRisk = $scorer->score(['duplicateRisk' => false]);

        $this->assertSame(0.0, $withRisk['score']);
        $this->assertSame(1.0, $withoutRisk['score']);
    }
}
