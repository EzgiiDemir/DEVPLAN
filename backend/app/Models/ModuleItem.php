<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_type', 'content', 'is_ai_generated', 'is_user_edited'])]
class ModuleItem extends Model
{
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_ai_generated' => 'boolean',
            'is_user_edited' => 'boolean',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
