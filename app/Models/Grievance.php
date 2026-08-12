<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GrievanceOriginLevel;
use App\Enums\GrievanceStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Grievance extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'reference_number',
        'submitted_by_user_id',
        'employee_id',
        'organization_id',
        'organization_unit_id',
        'origin_level',
        'category_id',
        'subject',
        'description',
        'status',
        'current_assigned_type',
        'current_assigned_id',
        'submitted_at',
        'requirement_checked_at',
        'requirement_fulfilled',
        'requirement_notes',
        'closed_at',
        'closed_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceStatus::class,
            'origin_level' => GrievanceOriginLevel::class,
            'requirement_fulfilled' => 'bool',
            'submitted_at' => 'datetime',
            'requirement_checked_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GrievanceCategory::class, 'category_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function currentAssigned(): MorphTo
    {
        return $this->morphTo('current_assigned');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(GrievanceAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(GrievanceAssignment::class)->where('is_current', true);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(GrievanceResponse::class);
    }

    public function latestResponse(): HasOne
    {
        return $this->hasOne(GrievanceResponse::class)->latestOfMany();
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(GrievanceEscalation::class);
    }

    public function decisionLetter(): HasOne
    {
        return $this->hasOne(GrievanceDecisionLetter::class);
    }

    public function tribunalCase(): HasOne
    {
        return $this->hasOne(AdministrativeTribunalCase::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->submitted_by_user_id === $user->id;
    }
}
