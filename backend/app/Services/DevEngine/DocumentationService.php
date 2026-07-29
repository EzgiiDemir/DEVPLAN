<?php

namespace App\Services\DevEngine;

use App\Services\AiTextGenerator;

/**
 * README updates ride through the exact same plan/diff-approval pipeline as
 * code changes (FeatureAgentService adds a synthetic README.md plan entry
 * when the project has one indexed) — this service only owns the prompt
 * that turns "current README + what changed" into an updated README, as an
 * incremental patch rather than a from-scratch regeneration so it never
 * clobbers the user's own wording. CHANGELOG entries are deterministic
 * (date + prompt + file list) and don't need AI, so they're applied
 * automatically on the frontend at apply-time rather than going through
 * this service or the approval screen.
 */
class DocumentationService
{
    private const MAX_README_CHARS = 6000;

    public function __construct(private AiTextGenerator $ai) {}

    public static function isReadmePath(string $path): bool
    {
        return strtolower(basename($path)) === 'readme.md';
    }

    public function generateReadmePatch(string $currentReadme, string $featurePrompt): string
    {
        $systemPrompt = 'You are updating a project README to reflect a feature that was just implemented. '
            .'Given the current README content and a description of the feature, respond with ONLY the complete '
            .'updated README content. Preserve existing sections and the author\'s own wording as much as possible — '
            .'only add or lightly adjust what is needed to document the new feature. No commentary, no markdown code fences.';

        $userPrompt = sprintf(
            "Current README:\n```\n%s\n```\n\nFeature that was just implemented: %s",
            mb_substr($currentReadme, 0, self::MAX_README_CHARS),
            $featurePrompt,
        );

        $raw = $this->ai->generate($systemPrompt, $userPrompt, 3000);

        return $this->stripCodeFences($raw);
    }

    private function stripCodeFences(string $text): string
    {
        $text = preg_replace('/^[ \t]*```[A-Za-z0-9]*\r?\n/', '', $text, 1);
        $text = preg_replace('/\r?\n[ \t]*```[ \t]*$/', '', $text, 1);

        return $text;
    }
}
