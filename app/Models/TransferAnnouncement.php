<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferAnnouncementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransferAnnouncement extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'position_id',
        'grade_level',
        'salary_min',
        'salary_max',
        'number_of_vacancies',
        'eligibility_rules',
        'required_documents',
        'opening_date',
        'closing_date',
        'status',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'eligibility_rules' => 'array',
            'required_documents' => 'array',
            'opening_date' => 'date',
            'closing_date' => 'date',
            'published_at' => 'datetime',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'number_of_vacancies' => 'integer',
            'status' => TransferAnnouncementStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(TransferAnnouncementPosition::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(TransferApplication::class, 'announcement_id');
    }

    /** Sum of all position vacancy_count values, falling back to the stored field. */
    public function totalVacancyCount(): int
    {
        if ($this->relationLoaded('positions') && $this->positions->isNotEmpty()) {
            return (int) $this->positions->sum('vacancy_count');
        }

        return $this->number_of_vacancies ?? 0;
    }

    public function isAcceptingApplications(): bool
    {
        return $this->status === TransferAnnouncementStatus::Published
            && $this->opening_date !== null
            && $this->closing_date !== null
            && $this->opening_date->lte(now())
            && $this->closing_date->gte(now());
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', TransferAnnouncementStatus::Published->value);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', TransferAnnouncementStatus::Published->value)
            ->where('opening_date', '<=', now())
            ->where('closing_date', '>=', now());
    }
}
