<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GroqService implements AiTextGenerator
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    public function generate(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): string
    {
        $apiKey = config('services.groq.key');

        if (! $apiKey) {
            throw new RuntimeException(trans('messages.ai_key_missing', ['provider' => 'Groq']));
        }

        $response = Http::withToken($apiKey)->post(self::API_URL, [
            'model' => config('services.groq.model'),
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(trans('messages.ai_request_failed', ['provider' => 'Groq', 'error' => $response->body()]));
        }

        return (string) $response->json('choices.0.message.content');
    }
}
