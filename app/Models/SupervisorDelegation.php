<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemporaryAuthorityStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supervisor lending named actions to a colleague for a period (ADR 0025).
 * The reporting line does not move: the delegate acts for the delegator, only
 * over the people the delegator supervises on the day, only for the listed
 * actions, and never over themself. Delegated authority cannot be delegated
 * again.
 */
class SupervisorDelegation extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id', 'delegator_employee_id', 'delegate_employee_id', 'actions', 'starts_on', 'ends_on', 'reason',
        'status', 'authorized_by', 'created_by', 'revoked_at', 'revoked_by', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => TemporaryAuthorityStatus::class,
            'actions' => 'array',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForceOn(Builder $query, Carbon|string|null $day = null): Builder
    {
        $date = $day === null ? today()->toDateString() : ($day instanceof Carbon ? $day->toDateString() : Carbon::parse($day)->toDateString());

        return $query
            ->where('status', TemporaryAuthorityStatus::Active->value)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    public function isInForceOn(Carbon|string|null $day = null): bool
    {
        $date = $day === null ? today()->toDateString() : ($day instanceof Carbon ? $day->toDateString() : Carbon::parse($day)->toDateString());

        return $this->status === TemporaryAuthorityStatus::Active
            && $this->starts_on !== null && $this->starts_on->toDateString() <= $date
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $date);
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions ?? [], true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegator_employee_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegate_employee_id');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
