<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'status', 'priority', 'module_item_id', 'assigned_to_user_id'])]
class Task extends Model
{
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function moduleItem(): BelongsTo
    {
        return $this->belongsTo(ModuleItem::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
