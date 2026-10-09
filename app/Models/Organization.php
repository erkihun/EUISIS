<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationStatus;
use App\Enums\RelationshipTargetType;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\Organizations\OrganizationDeletionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_type_id',
        'merged_into_id',
        'code',
        'name_en',
        'name_am',
        'legal_basis_ref',
        'status',
        'effective_from',
        'effective_to',
        'is_demo',
        'metadata',
        'logo_path',
        'branding_primary_color',
        'branding_secondary_color',
        'deleted_by',
        'deletion_reason',
    ];

    protected $appends = ['logo_url', 'has_logo'];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_demo' => 'bool',
            'metadata' => 'array',
        ];
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/storage/'.$this->logo_path : null;
    }

    public function getHasLogoAttribute(): bool
    {
        return $this->logo_path !== null;
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'organization_type_id');
    }

    /**
     * Alias for type() — preferred name per domain model.
     */
    public function organizationType(): BelongsTo
    {
        return $this->type();
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function nameHistories(): HasMany
    {
        return $this->hasMany(OrganizationNameHistory::class)->orderByDesc('effective_from');
    }

    public function parentEdges(): HasMany
    {
        return $this->hasMany(OrganizationEdge::class, 'child_organization_id');
    }

    public function childEdges(): HasMany
    {
        return $this->hasMany(OrganizationEdge::class, 'parent_organization_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function organizationUnits(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }

    public function institutionOffices(): HasMany
    {
        return $this->hasMany(InstitutionOffice::class, 'institution_id');
    }

    public function geographicInstitutionOffices(): HasMany
    {
        return $this->hasMany(InstitutionOffice::class, 'geographic_organization_id');
    }

    public function structurallyOwnedOffices(): HasMany
    {
        return $this->hasMany(InstitutionOffice::class, 'structural_organization_id');
    }

    public function reportedToByOffices(): HasMany
    {
        return $this->hasMany(InstitutionOfficeRelationship::class, 'target_id')
            ->where('target_type', RelationshipTargetType::Organization->value);
    }

    public function reportedToByUnits(): HasMany
    {
        return $this->hasMany(OrganizationUnitRelationship::class, 'target_id')
            ->where('target_type', RelationshipTargetType::Organization->value);
    }

    /**
     * Whether this organization has no dependent hierarchy edges, child
     * organizations, units, positions, assignments, or other references and
     * can therefore be physically (soft-)deleted. See
     * {@see OrganizationDeletionGuard} for the
     * detailed reason breakdown used by the policy and UI.
     */
    public function canBeDeletedSafely(): bool
    {
        return app(OrganizationDeletionGuard::class)->canBeDeletedSafely($this);
    }
}
