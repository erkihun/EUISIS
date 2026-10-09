<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VacancyAnnouncementPosition extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'vacancy_announcement_id',
        'position_establishment_id',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'vacancy_slots',
    ];

    protected function casts(): array
    {
        return [
            'vacancy_slots' => 'integer',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(VacancyAnnouncement::class, 'vacancy_announcement_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(PositionEstablishment::class, 'position_establishment_id');
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

    public function applications(): HasMany
    {
        return $this->hasMany(VacancyApplication::class, 'vacancy_announcement_position_id');
    }

    public function selectedCount(): int
    {
        return $this->applications()->whereIn('status', ['selected', 'transferred'])->count();
    }

    public function availableSlots(): int
    {
        return max(0, $this->vacancy_slots - $this->selectedCount());
    }
}
