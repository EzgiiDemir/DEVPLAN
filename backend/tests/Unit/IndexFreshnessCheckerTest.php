<?php

namespace Tests\Unit;

use App\Services\DevEngine\IndexFreshnessChecker;
use Tests\TestCase;

class IndexFreshnessCheckerTest extends TestCase
{
    public function test_matching_heads_are_not_stale(): void
    {
        $checker = new IndexFreshnessChecker;
        $this->assertFalse($checker->check('abc123', 'abc123')['stale']);
    }

    public function test_different_heads_are_stale(): void
    {
        $checker = new IndexFreshnessChecker;
        $this->assertTrue($checker->check('abc123', 'def456')['stale']);
    }

    public function test_never_scanned_is_not_stale(): void
    {
        $checker = new IndexFreshnessChecker;
        $this->assertFalse($checker->check(null, 'def456')['stale']);
    }

    public function test_no_current_head_reported_is_not_stale(): void
    {
        $checker = new IndexFreshnessChecker;
        $this->assertFalse($checker->check('abc123', null)['stale']);
    }

    public function test_both_missing_is_not_stale(): void
    {
        $checker = new IndexFreshnessChecker;
        $this->assertFalse($checker->check(null, null)['stale']);
    }
}
