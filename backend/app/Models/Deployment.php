<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'checkpoint_id', 'platform', 'status',
    'git_commit_hash', 'live_url', 'log_output', 'error_message',
    'companion_process_id', 'health_status', 'last_health_checked_at',
])]
class Deployment extends Model
{
    protected function casts(): array
    {
        return [
            'last_health_checked_at' => 'datetime',
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

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }
}
