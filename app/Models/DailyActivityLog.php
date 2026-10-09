<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DailyActivityStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's daily activity header for one work date.
 *
 * Deliberately has NO mass-assignable workflow or context columns: status,
 * reviewer, and the organization / unit / position snapshot are set only by
 * DailyActivityService with forceFill, from server-side data. A request that
 * smuggles `status` or `organization_id` into its payload has nothing to bind
 * to.
 */
class DailyActivityLog extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'late_reason',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'status' => DailyActivityStatus::class,
            'is_late' => 'bool',
            'first_submitted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'submission_count' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'employee_assignment_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DailyActivityItem::class)->orderBy('sort_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DailyActivityAttachment::class)->orderBy('created_at');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DailyActivityHistory::class)->orderBy('created_at');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function activityDateString(): string
    {
        return $this->activity_date->toDateString();
    }

    /**
     * Inclusive date range that stays index-friendly on every driver.
     *
     * A `date` cast is written as "Y-m-d 00:00:00" on SQLite, so a plain
     * whereBetween on two Y-m-d strings would drop the last day; bounding the
     * top at 23:59:59 matches it everywhere without wrapping the column in
     * DATE(), which would defeat the (organization_id, activity_date) index.
     */
    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween($query->qualifyColumn('activity_date'), [$from, $to.' 23:59:59']);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $this->scopeBetweenDates($query, $date, $date);
    }
}
