<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What an institution legally and administratively was before Addis One
 * (ADR-LB-002). A container over the design records that already exist,
 * carried through draft → under review → approved → published, and frozen
 * into a snapshot on publication. A later change is a new revision of the
 * same lineage; the published one is never edited.
 */
class LegacyBaseline extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    public const ORIGINS = ['manual', 'imported', 'mixed'];

    public const VALIDATION_STATUSES = ['not_run', 'passed', 'warnings', 'failed'];

    protected $fillable = [
        'organization_id', 'lineage_key', 'version_no', 'reference_number', 'title_en', 'title_am', 'description', 'origin', 'effective_date',
        'hierarchy_version_id', 'legal_source_id', 'status', 'validation_status', 'validation_summary', 'validated_at', 'mapping_required_count',
        'prepared_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_comment', 'approved_by', 'approved_at', 'approval_reference',
        'published_by', 'published_at', 'superseded_by_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'effective_date' => 'date:Y-m-d',
            'status' => OrganizationDesignStatus::class,
            'validation_summary' => 'array',
            'validated_at' => 'datetime',
            'mapping_required_count' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hierarchyVersion(): BelongsTo
    {
        return $this->belongsTo(HierarchyVersion::class);
    }

    public function legalSource(): BelongsTo
    {
        return $this->belongsTo(LegalSource::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LegacyBaselineStep::class, 'baseline_id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(LegacyBaselineSnapshot::class, 'baseline_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** Every revision of this baseline, oldest first. */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'lineage_key', 'lineage_key')->orderBy('version_no');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(LegalSourceCitation::class, 'subject_id')->where('subject_type', $this->getMorphClass());
    }
}
