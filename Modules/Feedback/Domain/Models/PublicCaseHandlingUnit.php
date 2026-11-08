<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An institution's existing work unit designated to handle public Tip-offs
 * or complaints (ADR 0034): each case type has its own unit. The unit itself stays in the organization
 * structure; this row only says it handles public cases and who serves in it.
 */
class PublicCaseHandlingUnit extends Model
{
    use HasUuidPrimaryKey;

    /** Service feedback, Tip-offs (internal type `suggestion`) and complaints: each module has its own unit. */
    public const CASE_TYPES = ['feedback', 'suggestion', 'complaint'];

    /** Each module's own admin address and route names (ADR 0034): never one shared page. */
    public const ROUTES = [
        'feedback' => 'service-feedback.units',
        'suggestion' => 'tip-offs.units',
        'complaint' => 'public-complaints.units',
    ];

    /**
     * ADR 0035: which hearing of a complaint the unit serves — the complaint
     * committee, the appeal committee (አቤቱታ ሰሚ ኮሚቴ) or the head of the
     * institution's office. Other case types keep first_instance.
     */
    public const STAGES = ['first_instance', 'appeal', 'head_office'];

    /** An institution has one appeal committee and one head's office at a time. */
    public const SINGLE_STAGES = ['appeal', 'head_office'];

    /** The cases that are routed and assigned (rating feedback is reviewed, not routed). */
    public const ROUTED_TYPES = ['suggestion', 'complaint'];

    protected $fillable = [
        'organization_id',
        'organization_unit_id',
        'case_type',
        'stage',
        'status',
        'description_en',
        'description_am',
        'created_by',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(PublicCaseHandlingUnitMember::class, 'handling_unit_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
