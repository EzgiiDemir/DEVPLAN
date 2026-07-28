<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicService implements AiTextGenerator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function generate(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): string
    {
        $apiKey = config('services.anthropic.key');

        if (! $apiKey) {
            throw new RuntimeException(trans('messages.ai_key_missing', ['provider' => 'Anthropic']));
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
        ])->post(self::API_URL, [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(trans('messages.ai_request_failed', ['provider' => 'Anthropic', 'error' => $response->body()]));
        }

        return collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }
}
