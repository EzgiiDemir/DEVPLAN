<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['path', 'language', 'content_hash', 'summary', 'symbols', 'unresolved_imports', 'last_scanned_at'])]
class ProjectFile extends Model
{
    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'unresolved_imports' => 'array',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function outgoingDependencies(): HasMany
    {
        return $this->hasMany(FileDependency::class, 'from_file_id');
    }

    public function incomingDependencies(): HasMany
    {
        return $this->hasMany(FileDependency::class, 'to_file_id');
    }
}
