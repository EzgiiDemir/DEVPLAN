<?php

namespace App\Services;

interface AiTextGenerator
{
    public function generate(string $systemPrompt, string $userPrompt, int $maxTokens = 1024): string;
}
