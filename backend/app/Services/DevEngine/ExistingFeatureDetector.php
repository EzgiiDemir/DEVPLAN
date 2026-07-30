<?php

namespace App\Services\DevEngine;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\ProjectFile;

/**
 * Runs before a feature plan is generated, checking whether the request
 * probably duplicates something that already exists — either a near-
 * identical earlier request, or an indexed file/model/route whose symbol
 * name already matches what's being asked for. Deterministic (no AI call),
 * non-blocking: a match is surfaced as a warning, it never prevents plan
 * generation. There's no embeddings/vector-similarity search in this
 * codebase to draw on — this is fuzzy text and symbol-name matching, not
 * semantic similarity, and is honest about that limit rather than claiming
 * more than it does.
 */
class ExistingFeatureDetector
{
    // similar_text()'s percent match between two lowercased prompts —
    // chosen empirically high enough that "add a wishlist" vs "add a
    // wishlist feature so users can save products" (a genuine near-repeat)
    // matches, while two unrelated feature prompts don't.
    private const PROMPT_SIMILARITY_THRESHOLD = 55.0;

    /**
     * @return array{type: string, feature_request_id?: int, prompt?: string, path?: string, symbol?: string}|null
     */
    public function detect(Project $project, string $prompt): ?array
    {
        $priorMatch = $this->findSimilarPriorRequest($project, $prompt);
        if ($priorMatch) {
            return [
                'type' => 'similar_prior_request',
                'feature_request_id' => $priorMatch->id,
                'prompt' => $priorMatch->prompt,
            ];
        }

        $symbolMatch = $this->findMatchingSymbol($project, $prompt);
        if ($symbolMatch) {
            return [
                'type' => 'existing_symbol',
                'path' => $symbolMatch['path'],
                'symbol' => $symbolMatch['symbol'],
            ];
        }

        return null;
    }

    private function findSimilarPriorRequest(Project $project, string $prompt): ?FeatureRequest
    {
        $normalizedPrompt = strtolower(trim($prompt));

        $priorRequests = $project->featureRequests()
            ->whereIn('status', ['applied', 'awaiting_diff_approval', 'awaiting_plan_approval'])
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'prompt']);

        foreach ($priorRequests as $prior) {
            similar_text($normalizedPrompt, strtolower(trim($prior->prompt)), $percent);
            if ($percent >= self::PROMPT_SIMILARITY_THRESHOLD) {
                return $prior;
            }
        }

        return null;
    }

    /**
     * @return array{path: string, symbol: string}|null
     */
    private function findMatchingSymbol(Project $project, string $prompt): ?array
    {
        $words = $this->significantWords($prompt);
        if (! $words) {
            return null;
        }

        $files = ProjectFile::where('project_id', $project->id)
            ->whereNotNull('symbols')
            ->get(['path', 'symbols']);

        foreach ($files as $file) {
            foreach (($file->symbols ?? []) as $symbol) {
                $symbolWords = $this->significantWords(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $symbol));
                foreach ($words as $word) {
                    foreach ($symbolWords as $symbolWord) {
                        if (strlen($word) >= 5 && $this->singularize($word) === $this->singularize($symbolWord)) {
                            return ['path' => $file->path, 'symbol' => $symbol];
                        }
                    }
                }
            }
        }

        return null;
    }

    // A trailing-"s" strip is not real stemming, but it's enough to match
    // "wishlists" (from a plural prompt) against a "Wishlist" model/symbol
    // name without pulling in a full NLP dependency for one heuristic check.
    private function singularize(string $word): string
    {
        return strlen($word) > 4 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
    }

    /**
     * @return array<int, string>
     */
    private function significantWords(string $text): array
    {
        preg_match_all('/[A-Za-z]{4,}/', $text, $matches);
        $stopwords = ['that', 'this', 'with', 'from', 'have', 'should', 'would', 'could', 'able', 'when', 'also', 'feature', 'users', 'user', 'save', 'saves'];

        return array_values(array_unique(array_diff(array_map('strtolower', $matches[0]), $stopwords)));
    }
}
