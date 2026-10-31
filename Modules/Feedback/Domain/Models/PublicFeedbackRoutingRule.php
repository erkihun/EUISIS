<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Feedback\Contracts\Data\PublicTipType;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;

/**
 * Where a new suggestion or complaint goes (brief §45). A rule matches on
 * organization, target type, submission type, Tip-off type and work unit —
 * each either a value or "any" — and names one handler. A corruption Tip-off
 * is matched only by a rule naming that tip type (PublicFeedbackRoutingService). The most specific rule in effect
 * wins; ties go to the lowest priority number. A rule that routed something
 * is retired, never deleted, so assignment history keeps its reference.
 */
class PublicFeedbackRoutingRule extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id',
        'target_type',
        'submission_type',
        'tip_type',
        'organization_unit_id',
        'handler_type',
        'handler_id',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'handler_type' => PublicFeedbackHandlerType::class,
            'tip_type' => PublicTipType::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The relevant work unit this rule is narrowed to (null = any). This is
     * what the submission is *about*, never who handles it: the handler is
     * named separately in handler_type/handler_id.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PublicFeedbackAssignment::class, 'routing_rule_id');
    }

    /** Active and inside its effective window right now. */
    public function scopeInEffect(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $now));
    }

    public function isCityWide(): bool
    {
        return $this->organization_id === null;
    }

    public function isReferenced(): bool
    {
        return $this->assignments()->exists();
    }
}
