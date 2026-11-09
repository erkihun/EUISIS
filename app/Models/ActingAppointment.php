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
 * An employee temporarily filling a position — usually a head position whose
 * holder is absent (ADR 0025). While it runs, the acting employee is the
 * person the reporting line resolves to for that position; the substantive
 * holder's assignment is untouched. Expiry is by date, revocation by row.
 */
class ActingAppointment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id', 'position_id', 'employee_id', 'starts_on', 'ends_on', 'reason',
        'status', 'authorized_by', 'created_by', 'revoked_at', 'revoked_by', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => TemporaryAuthorityStatus::class,
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Active and covering the day.
     *
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
