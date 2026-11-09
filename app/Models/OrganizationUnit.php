<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationUnitStatus;
use App\Models\Concerns\HasIntegrationEvents;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\OrganizationUnitType as OrganizationUnitTypeModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationUnit extends Model
{
    use HasIntegrationEvents;
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'parent_unit_id',
        'organization_unit_type_id',
        'institution_office_id',
        'unit_type',
        'code',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'status',
        'is_public_feedback_selectable',
        'effective_from',
        'effective_to',
        'sort_order',
        'metadata',
        'created_by',
        'updated_by',
        'deleted_by',
        'deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            // unit_type is kept as a plain string to allow custom type codes beyond the enum
            'status' => OrganizationUnitStatus::class,
            'is_public_feedback_selectable' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnitTypeModel::class, 'organization_unit_type_id');
    }

    public function institutionOffice(): BelongsTo
    {
        return $this->belongsTo(InstitutionOffice::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(OrganizationUnitRelationship::class, 'source_unit_id');
    }

    public function activeRelationships(): HasMany
    {
        return $this->relationships()->active();
    }

    public function functionalReportingRelationships(): HasMany
    {
        return $this->activeRelationships()->where('relationship_type', OrganizationRelationshipType::FunctionalReporting->value);
    }

    public function technicalSupervisionRelationships(): HasMany
    {
        return $this->activeRelationships()->where('relationship_type', OrganizationRelationshipType::TechnicalSupervision->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OrganizationUnitStatus::Active->value);
    }

    public function scopeForOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected static function booted(): void
    {
        static::creating(function (self $unit): void {
            // The earlier public feedback flow deliberately exposed service
            // offices. All non-office unit types stay private unless opted in.
            if ($unit->getAttribute('is_public_feedback_selectable') === null
                && ($unit->unit_type === 'office' || $unit->institution_office_id !== null)) {
                $unit->is_public_feedback_selectable = true;
            }
        });
    }
}
