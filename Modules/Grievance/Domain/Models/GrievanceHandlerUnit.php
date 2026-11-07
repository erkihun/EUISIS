<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An existing organization unit designated as a Grievance Team or Grievance
 * Directorate (ADR 0029). The unit itself stays in the organization structure;
 * this row only records the designation and owns the named member terms.
 */
class GrievanceHandlerUnit extends Model
{
    use HasUuidPrimaryKey;

    public const KINDS = ['team', 'directorate'];

    protected $fillable = [
        'organization_id',
        'organization_unit_id',
        'kind',
        'status',
        'description_en',
        'description_am',
        'created_by',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    /** @return HasMany<GrievanceHandlerUnitMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GrievanceHandlerUnitMember::class, 'handler_unit_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
