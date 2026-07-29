<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'prompt', 'status'])]
class FeatureRequest extends Model
{
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changeSet(): HasOne
    {
        return $this->hasOne(ChangeSet::class);
    }

    // A feature produces two checkpoints (before + after applying), so this
    // is HasMany even though most other DevEngine relations here are 1:1.
    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }
}
