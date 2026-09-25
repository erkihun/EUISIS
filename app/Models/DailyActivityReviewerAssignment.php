<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Grants one user review authority over the daily activity of an
 * organization, a unit (optionally with sub-units), or one employee.
 */
class DailyActivityReviewerAssignment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'reviewer_user_id',
        'organization_id',
        'organization_unit_id',
        'include_sub_units',
        'employee_id',
        'is_active',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'include_sub_units' => 'bool',
            'is_active' => 'bool',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /** Active and in effect on the given date (today when omitted). */
    public function scopeEffective(Builder $query, ?Carbon $on = null): Builder
    {
        $date = ($on ?? Carbon::today())->toDateString();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
