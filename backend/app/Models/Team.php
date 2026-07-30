<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'personal'])]
class Team extends Model
{
    protected function casts(): array
    {
        return [
            'personal' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
