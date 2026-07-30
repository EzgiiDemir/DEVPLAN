<?php

namespace App\Models;

use App\Services\RiskAnalyzer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_file_id', 'path', 'action', 'reason', 'diff', 'new_content', 'architecture_warning', 'security_findings', 'base_content_hash', 'conflict_warning', 'plan_approved', 'diff_approved', 'applied'])]
class ChangeSetFile extends Model
{
    // Always present in every response this model appears in (the plan/diff
    // review screens, and Maya messages that eager-load feature_request.
    // changeSet.files) — one computed source instead of patching each
    // controller that happens to return these rows.
    protected $appends = ['risk_level'];

    protected function casts(): array
    {
        return [
            'plan_approved' => 'boolean',
            'diff_approved' => 'boolean',
            'applied' => 'boolean',
            'security_findings' => 'array',
            'conflict_warning' => 'array',
        ];
    }

    protected function riskLevel(): Attribute
    {
        return Attribute::make(
            get: fn () => app(RiskAnalyzer::class)->classifyFile($this->path, $this->action, $this->new_content),
        );
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
