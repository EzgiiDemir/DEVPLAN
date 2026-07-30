<?php

namespace App\Services\DevEngine;

use App\Models\MayaMessage;
use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;

/**
 * The entry point for every message sent to Maya. Two AI calls per turn,
 * never more: a cheap intent classification, then either one conversational
 * reply (chat/explain/debug — read-only, no disk risk) or a delegation into
 * the existing, unmodified FeatureAgentService plan pipeline
 * (refactor/feature_request — the same code path FeatureController already
 * uses, not a second implementation of it).
 */
class MayaChatService
{
    private const INTENTS = ['chat', 'explain', 'debug', 'refactor', 'feature_request'];

    // 'test', 'fix', and 'deploy_config' are never classified from typed
    // chat text — they only ever arrive via handleDirectFeatureAction(),
    // triggered by an explicit button click (Generate Tests / Suggest Fix /
    // Generate a missing deploy config file) that already knows its own
    // intent, so they're listed here but deliberately absent from INTENTS.
    private const FEATURE_INTENTS = ['refactor', 'feature_request', 'test', 'fix', 'deploy_config'];

    public function __construct(
        private AiTextGenerator $ai,
        private ContextEngineService $contextEngine,
        private FeatureAgentService $featureAgent,
    ) {}

    public function handleMessage(Project $project, User $user, string $message, ?string $activeFile = null): array
    {
        $userMessage = $project->mayaMessages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $message,
        ]);

        $classification = $this->classifyIntent($message);
        $intent = in_array($classification['intent'] ?? null, self::INTENTS, true)
            ? $classification['intent']
            : 'chat';
        $userMessage->update(['intent' => $intent]);

        if (in_array($intent, self::FEATURE_INTENTS, true)) {
            return $this->handleFeatureIntent($project, $user, $userMessage, $intent, $message, $activeFile);
        }

        return $this->handleConversational($project, $user, $userMessage, $intent, $message, $activeFile);
    }

    /**
     * Entry point for actions that already know their own intent — the
     * "Generate Tests" and "Suggest Fix" buttons — bypassing classifyIntent()
     * entirely rather than asking the AI to guess something the caller
     * already knows for certain.
     */
    public function handleDirectFeatureAction(Project $project, User $user, string $intent, string $prompt, ?string $activeFile = null): array
    {
        $userMessage = $project->mayaMessages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'intent' => $intent,
            'content' => $prompt,
        ]);

        return $this->handleFeatureIntent($project, $user, $userMessage, $intent, $prompt, $activeFile);
    }

    private function classifyIntent(string $message): array
    {
        $systemPrompt = 'Classify a message sent to an AI software engineering assistant embedded in a real project. '
            .'Respond with ONLY a JSON object: {"intent": "chat|explain|debug|refactor|feature_request", "subject": "short phrase naming what is being discussed"}. '
            .'"explain" = asking how something works or about architecture. "debug" = describing a bug, error, or something broken to investigate. '
            .'"refactor" = asking to improve, clean up, or restructure existing code without adding new behavior. '
            .'"feature_request" = asking to add new functionality. "chat" = anything else (general questions, discussion). '
            .'No prose outside the JSON.';

        $raw = $this->ai->generate($systemPrompt, $message, 200);
        $parsed = $this->parseJson($raw);

        return is_array($parsed) ? $parsed : ['intent' => 'chat', 'subject' => null];
    }

    private function handleFeatureIntent(Project $project, User $user, MayaMessage $userMessage, string $intent, string $message, ?string $activeFile = null): array
    {
        $featureRequest = $this->featureAgent->createAndPlan($project, $user, $message, $activeFile);

        $ackText = match ($intent) {
            'refactor' => "I've put together a refactor plan — review it below.",
            'test' => "I've generated tests — review them below.",
            'fix' => "I've put together a fix for this — review it below.",
            'deploy_config' => "I've put together the deployment config — review it below.",
            default => "I've put together a plan for this — review it below.",
        };

        $assistantMessage = $project->mayaMessages()->create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'intent' => $intent,
            'content' => $ackText,
            'feature_request_id' => $featureRequest->id,
        ]);

        return [
            'messages' => [$userMessage->fresh(), $assistantMessage],
            'change_set' => $featureRequest->changeSet,
        ];
    }

    private function handleConversational(Project $project, User $user, MayaMessage $userMessage, string $intent, string $message, ?string $activeFile): array
    {
        [$systemPrompt, $contextBlock] = $this->buildConversationalPrompt($project, $intent, $message, $activeFile);

        $userPrompt = sprintf("Project context:\n%s\n\nUser message: %s", $contextBlock, $message);

        $reply = trim($this->ai->generate($systemPrompt, $userPrompt, 1500));

        $assistantMessage = $project->mayaMessages()->create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'intent' => $intent,
            'content' => $reply,
        ]);

        return [
            'messages' => [$userMessage->fresh(), $assistantMessage],
            'change_set' => null,
        ];
    }

    /**
     * @return array{0: string, 1: string} [systemPrompt, contextBlock]
     */
    private function buildConversationalPrompt(Project $project, string $intent, string $message, ?string $activeFile): array
    {
        $filesBlock = $this->contextEngine->relatedFiles($project, $message, $activeFile)
            ->map(function ($f) {
                $symbols = $f->symbols ? ' ['.implode(', ', $f->symbols).']' : '';

                return sprintf('- %s (%s): %s%s', $f->path, $f->language ?? 'unknown', $f->summary ?? 'no summary', $symbols);
            })
            ->implode("\n") ?: '(no files indexed yet — run a codebase scan first)';

        return match ($intent) {
            'explain' => [
                "You are Maya, an AI software engineer embedded in this project. Explain the requested topic clearly.\n"
                .'Structure your answer with these sections: How it works / Files involved / Data flow / Security concerns. '
                .'Ground your answer only in the real project files, spec, and API endpoints given below — never invent '
                .'files or behavior that aren\'t there.',
                sprintf(
                    "Related files:\n%s\n\nProduct spec:\n%s\n\nAPI endpoints:\n%s",
                    $filesBlock,
                    $this->contextEngine->projectSpec($project),
                    $this->contextEngine->apiEndpoints($project),
                ),
            ],
            'debug' => [
                'You are Maya, an AI software engineer embedded in this project, helping debug a reported issue. '
                .'There is no separate error-log source in this system — the user\'s message IS the bug report. '
                .'Structure your answer with these sections: Problem / Cause / Affected files / Solution. '
                .'Ground your answer only in the real project files, recent changes, and schema given below.',
                sprintf(
                    "Related files:\n%s\n\nRecent activity:\n%s\n\nDatabase schema:\n%s",
                    $filesBlock,
                    $this->contextEngine->recentActivity($project),
                    $this->contextEngine->databaseSchema($project),
                ),
            ],
            default => [
                'You are Maya, an AI software engineer embedded in this project. Answer the user\'s question helpfully '
                .'and concisely, grounded only in the real project context given below — never invent files or behavior '
                .'that aren\'t there. Recent activity entries show who asked for what; use that attribution when it\'s '
                .'relevant to the question (e.g. "what is X working on", "what\'s the sprint status").',
                sprintf(
                    "Related files:\n%s\n\nRecent activity:\n%s\n\nTask/sprint status:\n%s",
                    $filesBlock,
                    $this->contextEngine->recentActivity($project),
                    $this->contextEngine->sprintStatus($project),
                ),
            ],
        };
    }

    private function parseJson(string $text): ?array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);

        $decoded = json_decode(trim($trimmed), true);

        return is_array($decoded) ? $decoded : null;
    }
}
