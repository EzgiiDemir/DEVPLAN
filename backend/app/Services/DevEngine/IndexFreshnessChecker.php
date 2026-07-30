<?php

namespace App\Services\DevEngine;

/**
 * Detects whether the Project Brain's index might be stale relative to the
 * user's actual working tree — comparing the git HEAD as of the last scan
 * (stored on the project) against the HEAD Companion reports right now.
 * This is the detection half only: it doesn't rescan anything itself, it
 * just tells the frontend whether suggesting a rescan is warranted.
 * Deliberately simple — a HEAD mismatch means "something changed since we
 * last looked", not an assessment of *how much* changed (that's still what
 * the existing content-hash-based diff() does once a rescan runs).
 */
class IndexFreshnessChecker
{
    /**
     * @return array{stale: bool}
     */
    public function check(?string $storedHead, ?string $currentHead): array
    {
        // Never scanned yet, or the caller couldn't report a HEAD (no git
        // repo yet, Companion not paired) — nothing to compare against, so
        // this is "unknown", not "stale".
        if (! $storedHead || ! $currentHead) {
            return ['stale' => false];
        }

        return ['stale' => $storedHead !== $currentHead];
    }
}
