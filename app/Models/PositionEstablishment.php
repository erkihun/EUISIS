<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstablishmentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PositionEstablishment extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'establishment_number',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'occupation_id',
        'approved_slots',
        'effective_from',
        'effective_to',
        'status',
        'approval_reference',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => EstablishmentStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
            'approved_slots' => 'integer',
        ];
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

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(PositionOccupancy::class);
    }

    public function vacancyAnnouncements(): HasMany
    {
        return $this->hasMany(VacancyAnnouncementPosition::class);
    }

    public function activeOccupanciesCount(): int
    {
        return $this->occupancies()->where('status', 'active')->count();
    }

    public function availableSlots(): int
    {
        return max(0, $this->approved_slots - $this->activeOccupanciesCount());
    }

    public function hasVacancy(): bool
    {
        return $this->status === EstablishmentStatus::Approved && $this->availableSlots() > 0;
    }
}
