<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_file_id', 'path', 'action', 'reason', 'diff', 'new_content', 'plan_approved', 'diff_approved', 'applied'])]
class ChangeSetFile extends Model
{
    protected function casts(): array
    {
        return [
            'plan_approved' => 'boolean',
            'diff_approved' => 'boolean',
            'applied' => 'boolean',
        ];
    }

    public function changeSet(): BelongsTo
    {
        return $this->belongsTo(ChangeSet::class);
    }

    public function projectFile(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class);
    }
}
