<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A position. Its lifecycle (ADR 0010) is explicit — draft, approved,
 * suspended, abolished, archived — and is_active is kept in step (true only
 * when approved), so every rule that reads is_active still holds. Whether it
 * is vacant or occupied is derived from its placements and seats.
 */
class Position extends Model
{
    use HasUuids, SoftDeletes;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const SUSPENDED = 'suspended';

    public const ABOLISHED = 'abolished';

    public const ARCHIVED = 'archived';

    public const LIFECYCLE = [self::DRAFT, self::APPROVED, self::SUSPENDED, self::ABOLISHED, self::ARCHIVED];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (Position $position): void {
            if (! self::hasLifecycle($position)) {
                return; // a database without the P3 columns (the EUISIS stand-in the import tests copy from)
            }
            if ($position->isDirty('lifecycle_status') || ! $position->exists && $position->lifecycle_status !== null) {
                $position->is_active = $position->lifecycle_status === self::APPROVED;
            } elseif ($position->isDirty('is_active')) {
                // The older on/off switch: on is approved, off is archived (kept for history).
                $position->lifecycle_status = $position->is_active ? self::APPROVED : self::ARCHIVED;
            }
        });
    }

    private static function hasLifecycle(Position $position): bool
    {
        static $known = [];
        $connection = $position->getConnectionName() ?? (string) config('database.default');

        return $known[$connection] ??= $position->getConnection()->getSchemaBuilder()->hasColumn('positions', 'lifecycle_status');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'job_grade_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class, 'job_level_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PositionCategory::class, 'position_category_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /** Active current placements: the people holding this seat now. */
    public function holders(): HasMany
    {
        return $this->assignments()->where('is_current', true)->where('assignment_status', EmployeeAssignment::ACTIVE);
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(PositionEstablishment::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PositionHistory::class)->orderByDesc('id');
    }
}
