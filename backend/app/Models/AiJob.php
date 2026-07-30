<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A background unit of work wrapping exactly one existing, unmodified AI
 * service call (FeatureAgentService::createAndPlan/generateContent,
 * MayaChatService::handleMessage) — created by a controller in place of
 * calling that service directly, so the HTTP request can return immediately
 * instead of blocking for the whole LLM round trip. The frontend polls
 * `status`/`result`/`error` via AiJobController::show() until it resolves.
 */
#[Fillable(['project_id', 'user_id', 'type', 'status', 'payload', 'result', 'error', 'attempts', 'cancelled_at'])]
class AiJob extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
