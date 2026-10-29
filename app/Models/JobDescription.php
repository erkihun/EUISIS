<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job description (brief §64–66): one document per version, built from
 * the published position profile and the linked work. Draft → under_review
 * → approved → published → superseded; published versions never change.
 */
class JobDescription extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'position_id', 'organization_id', 'position_profile_id', 'lineage_key', 'version_no', 'reference_number', 'status', 'effective_from', 'effective_to',
        'prepared_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_comment', 'approved_by', 'approved_at', 'published_by', 'published_at', 'generated_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer', 'status' => OrganizationDesignStatus::class, 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d',
            'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'generated_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PositionProfile::class, 'position_profile_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(JobDescriptionSection::class)->orderBy('sequence');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'lineage_key', 'lineage_key')->orderBy('version_no');
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

    public function isEditable(): bool
    {
        return $this->status === OrganizationDesignStatus::Draft;
    }
}
