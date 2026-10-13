<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One proposed unit change inside a design study (brief §36): create a
 * unit, or update / move / deactivate an existing one. An accepted
 * proposal becomes one organizational change request; when that request
 * is implemented the proposal adopts the resulting unit.
 */
class UnitDesignProposal extends Model
{
    use HasUuidPrimaryKey;

    public const TYPES = ['create', 'update', 'move', 'deactivate'];

    public const STATUSES = ['proposed', 'accepted', 'converted', 'implemented', 'rejected'];

    protected $fillable = [
        'study_id', 'organization_id', 'proposal_type', 'existing_unit_id', 'parent_proposed_unit_id', 'parent_existing_unit_id', 'proposed_unit_type_id', 'unit_type',
        'name_en', 'name_am', 'description_en', 'description_am', 'design_rationale', 'effective_from', 'effective_to', 'sequence', 'status',
        'organizational_change_request_id', 'resulting_unit_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'sequence' => 'integer'];
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(OrganizationDesignStudy::class, 'study_id');
    }

    public function existingUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'existing_unit_id');
    }

    public function parentProposed(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_proposed_unit_id');
    }

    public function parentExisting(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'parent_existing_unit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_proposed_unit_id')->orderBy('sequence');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnitType::class, 'proposed_unit_type_id');
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(OrganizationalChangeRequest::class, 'organizational_change_request_id');
    }

    public function resultingUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'resulting_unit_id');
    }

    public function responsibilities(): HasMany
    {
        return $this->hasMany(UnitResponsibility::class, 'unit_design_proposal_id')->orderBy('sequence');
    }

    /** The unit this proposal stands for once implemented, or the existing unit it changes. */
    public function unitId(): ?string
    {
        return $this->resulting_unit_id ?? $this->existing_unit_id;
    }
}
