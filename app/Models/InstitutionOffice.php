<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstitutionOfficeLevel;
use App\Enums\InstitutionOfficeStatus;
use App\Enums\OrganizationRelationshipType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstitutionOffice extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'institution_id',
        'structural_organization_id',
        'geographic_organization_id',
        'parent_office_id',
        'office_level',
        'office_code',
        'name_en',
        'name_am',
        'short_name_en',
        'short_name_am',
        'assigned_scope_type',
        'is_head_office',
        'status',
        'opened_on',
        'closed_on',
        'address_en',
        'address_am',
        'phone_number',
        'email',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'office_level' => InstitutionOfficeLevel::class,
            'status' => InstitutionOfficeStatus::class,
            'is_head_office' => 'bool',
            'opened_on' => 'date',
            'closed_on' => 'date',
            'metadata' => 'array',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'institution_id');
    }

    public function geographicOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'geographic_organization_id');
    }

    public function structuralOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'structural_organization_id');
    }

    public function parentOffice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_office_id');
    }

    public function childOffices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_office_id')->orderBy('name_en');
    }

    public function organizationUnits(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(InstitutionOfficeRelationship::class, 'source_office_id');
    }

    public function activeRelationships(): HasMany
    {
        return $this->relationships()->active();
    }

    public function structuralParentRelationship(): HasMany
    {
        return $this->activeRelationships()->structuralParent()->where('is_primary', true);
    }

    public function functionalReportingRelationships(): HasMany
    {
        return $this->activeRelationships()->where('relationship_type', OrganizationRelationshipType::FunctionalReporting->value);
    }

    public function technicalSupervisionRelationships(): HasMany
    {
        return $this->activeRelationships()->where('relationship_type', OrganizationRelationshipType::TechnicalSupervision->value);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', InstitutionOfficeStatus::Active->value);
    }

    public function scopeForInstitution(Builder $query, string $institutionId): Builder
    {
        return $query->where('institution_id', $institutionId);
    }
}
