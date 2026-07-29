<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'feature_request_id', 'framework', 'status',
    'passed_count', 'failed_count', 'total_count', 'duration_ms',
    'failures', 'coverage_percent',
])]
class TestRun extends Model
{
    protected function casts(): array
    {
        return [
            'failures' => 'array',
            // Without an explicit cast, a numeric column can come back as a
            // PHP string depending on which query path fetched it (found via
            // a real coverage_percent value serializing as "100" in one
            // response and 100 in another) — cast it consistently everywhere.
            'coverage_percent' => 'float',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }
}
