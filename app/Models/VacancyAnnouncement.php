<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VacancyAnnouncementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VacancyAnnouncement extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'announcement_number',
        'title_en',
        'title_am',
        'description_en',
        'description_am',
        'application_opens_at',
        'application_closes_at',
        'status',
        'eligibility_rules',
        'published_by',
        'published_at',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VacancyAnnouncementStatus::class,
            'application_opens_at' => 'datetime',
            'application_closes_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'eligibility_rules' => 'array',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(VacancyAnnouncementPosition::class, 'vacancy_announcement_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(VacancyApplication::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isAcceptingApplications(): bool
    {
        if ($this->status !== VacancyAnnouncementStatus::Published) {
            return false;
        }

        $now = now();

        if ($this->application_opens_at !== null && $this->application_opens_at->isAfter($now)) {
            return false;
        }

        return ! ($this->application_closes_at !== null && $this->application_closes_at->isBefore($now));
    }

    public function selectedCount(): int
    {
        return $this->applications()->whereIn('status', ['selected', 'transferred'])->count();
    }

    public function allPositionsFull(): bool
    {
        return $this->positions()->get()->every(fn ($p) => $p->availableSlots() <= 0);
    }
}
