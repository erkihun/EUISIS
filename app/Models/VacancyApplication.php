<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VacancyApplicationStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VacancyApplication extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'application_number',
        'vacancy_announcement_id',
        'vacancy_announcement_position_id',
        'employee_id',
        'current_organization_id',
        'current_position_id',
        'status',
        'applied_at',
        'withdrawn_at',
        'screening_score',
        'screening_notes',
        'screened_by',
        'screened_at',
        'shortlisted_by',
        'shortlisted_at',
        'selected_by',
        'selected_at',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
        'transfer_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => VacancyApplicationStatus::class,
            'applied_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'screened_at' => 'datetime',
            'shortlisted_at' => 'datetime',
            'selected_at' => 'datetime',
            'rejected_at' => 'datetime',
            'screening_score' => 'decimal:2',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(VacancyAnnouncement::class, 'vacancy_announcement_id');
    }

    public function positionEntry(): BelongsTo
    {
        return $this->belongsTo(VacancyAnnouncementPosition::class, 'vacancy_announcement_position_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function currentPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'current_position_id');
    }

    public function screenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
    }

    public function shortlistedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shortlisted_by');
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(EmployeeTransfer::class, 'transfer_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VacancyApplicationDocument::class);
    }
}
