<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The roll-up of a plan's lines by unit × occupation × profession (brief
 * §56–58): what the organization needs, approved as headcount, and the
 * position + establishment it became. Never an assignment.
 */
class PositionRequirement extends Model
{
    use HasUuidPrimaryKey;

    public const STATUSES = ['proposed', 'approved', 'implemented'];

    protected $fillable = [
        'plan_id', 'organization_id', 'organization_unit_id', 'unit_design_proposal_id', 'occupation_id', 'profession_id', 'position_title_en', 'position_title_am',
        'grade_level', 'position_type', 'required_fte', 'approved_headcount', 'status', 'position_id', 'establishment_id', 'sequence', 'implemented_at',
    ];

    protected function casts(): array
    {
        return ['required_fte' => 'decimal:4', 'approved_headcount' => 'integer', 'sequence' => 'integer', 'implemented_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ManpowerPlan::class, 'plan_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(UnitDesignProposal::class, 'unit_design_proposal_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(PositionEstablishment::class, 'establishment_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ManpowerPlanLine::class, 'position_requirement_id');
    }
}
