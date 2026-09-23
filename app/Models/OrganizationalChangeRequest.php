<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationalChangeRequestCategory;
use App\Enums\OrganizationalChangeRequestStatus;
use App\Enums\OrganizationalChangeRequestType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A request to change organizational structure or position data.
 *
 * The request holds the proposal; it never holds the authority to apply it.
 * Approval sets approved_payload_hash and moves the request to
 * PendingImplementation, from where only the implementing unit can act.
 */
class OrganizationalChangeRequest extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $table = 'organizational_change_requests';

    protected $fillable = [
        'request_no',
        'organization_id',
        'request_type',
        'category',
        'status',
        'priority',
        'requested_by',
        'reason',
        'requested_effective_date',
        'submitted_at',
        'reviewed_by',
        'review_started_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'decision_comment',
        'approved_payload',
        'approved_payload_hash',
        'implementing_unit_key',
        'implementing_unit_id',
        'implementation_assigned_to',
        'implementation_assigned_by',
        'implementation_assigned_at',
        'implementation_started_at',
        'implementation_claimed_by',
        'implemented_at',
        'implemented_by',
        'completed_at',
        'completed_by',
        'implementation_note',
        'implementation_result',
        'blocked_reasons',
        'blocked_at',
        'cancelled_at',
        'cancelled_by',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationalChangeRequestStatus::class,
            'request_type' => OrganizationalChangeRequestType::class,
            'category' => OrganizationalChangeRequestCategory::class,
            'requested_effective_date' => 'date',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'implementation_assigned_at' => 'datetime',
            'implementation_started_at' => 'datetime',
            'implemented_at' => 'datetime',
            'completed_at' => 'datetime',
            'blocked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'approved_payload' => 'array',
            'implementation_result' => 'array',
            'blocked_reasons' => 'array',
            'revision' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function implementingUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'implementing_unit_id');
    }

    public function implementationAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implementation_assigned_to');
    }

    public function implementer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implemented_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrganizationalChangeRequestItem::class, 'request_id')->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OrganizationalChangeRequestReview::class, 'request_id')->orderBy('reviewed_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrganizationalChangeRequestAttachment::class, 'request_id')->latest('created_at');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrganizationalChangeRequestHistory::class, 'request_id')->orderBy('created_at');
    }

    /** The single item every current request type carries. */
    public function primaryItem(): ?OrganizationalChangeRequestItem
    {
        return $this->items->first();
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /** @param  Builder<self>  $query */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrganizationalChangeRequestStatus::Submitted->value,
            OrganizationalChangeRequestStatus::UnderReview->value,
            OrganizationalChangeRequestStatus::Resubmitted->value,
        ]);
    }

    /** @param  Builder<self>  $query */
    public function scopeAwaitingImplementation(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrganizationalChangeRequestStatus::Approved->value,
            OrganizationalChangeRequestStatus::PendingImplementation->value,
            OrganizationalChangeRequestStatus::Implementing->value,
            OrganizationalChangeRequestStatus::ImplementationBlocked->value,
        ]);
    }

    /** @param  Builder<self>  $query */
    public function scopeConcluded(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrganizationalChangeRequestStatus::Implemented->value,
            OrganizationalChangeRequestStatus::Completed->value,
            OrganizationalChangeRequestStatus::Rejected->value,
            OrganizationalChangeRequestStatus::Cancelled->value,
        ]);
    }
}
