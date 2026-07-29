<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'local_path', 'workspace_state', 'quality_snapshot', 'quality_scanned_at'])]
class Project extends Model
{
    protected function casts(): array
    {
        return [
            'workspace_state' => 'array',
            'quality_snapshot' => 'array',
            'quality_scanned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)->orderBy('order_index');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class);
    }

    public function featureRequests(): HasMany
    {
        return $this->hasMany(FeatureRequest::class);
    }

    public function checkpoints(): HasMany
    {
        // orderByDesc('id') rather than latest() — the before/after pair for
        // one applied feature is created within the same second, so
        // created_at alone ties (same issue already found and fixed for
        // maya_messages/test_runs).
        return $this->hasMany(Checkpoint::class)->orderByDesc('id');
    }

    public function mayaMessages(): HasMany
    {
        return $this->hasMany(MayaMessage::class);
    }

    public function testRuns(): HasMany
    {
        // orderByDesc('id') rather than latest() — created_at ties when two
        // runs complete within the same second, same issue already found and
        // fixed for maya_messages/checkpoints.
        return $this->hasMany(TestRun::class)->orderByDesc('id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->orderByDesc('id');
    }
}
