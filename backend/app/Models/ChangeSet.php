<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'summary', 'confidence_level', 'confidence_score', 'duplicate_warning'])]
class ChangeSet extends Model
{
    protected function casts(): array
    {
        return [
            'duplicate_warning' => 'array',
        ];
    }

    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ChangeSetFile::class);
    }
}
