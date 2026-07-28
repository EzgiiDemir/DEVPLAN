<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan', 'stripe_customer_id'])]
class Subscription extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
