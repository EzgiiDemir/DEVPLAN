<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_file_id', 'to_file_id', 'kind'])]
class FileDependency extends Model
{
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fromFile(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class, 'from_file_id');
    }

    public function toFile(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class, 'to_file_id');
    }
}
