<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DesignStudyType;
use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A design study (brief §112–113): the staged workspace in which an
 * institution proposes its units and maps responsibilities before the
 * change-request pipeline applies the result (D-1 = b).
 */
class OrganizationDesignStudy extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'reference_number', 'title_en', 'title_am', 'study_type', 'reason', 'scope_notes', 'status', 'started_at', 'target_effective_date',
        'hierarchy_version_id', 'prepared_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_comment', 'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'study_type' => DesignStudyType::class,
            'status' => OrganizationDesignStatus::class,
            'started_at' => 'date',
            'target_effective_date' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function proposals(): HasMany
    {
        return $this->hasMany(UnitDesignProposal::class, 'study_id')->orderBy('sequence')->orderBy('created_at');
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

    /** Proposals and their responsibilities change only while the study is being drafted. */
    public function isEditable(): bool
    {
        return $this->status === OrganizationDesignStatus::Draft;
    }
}
