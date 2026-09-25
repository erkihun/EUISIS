<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\KpiHealth;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A developmental conversation. Never produces a score. manager_private_note is never shown to the employee.
 */
class PerformanceCheckin extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_checkins';

    protected $fillable = [
        'agreement_id',
        'employee_id',
        'manager_user_id',
        'checkin_date',
        'period_start',
        'period_end',
        'employee_summary',
        'manager_comment',
        'manager_private_note',
        'progress_status',
        'blockers',
        'support_required',
        'learning_needs',
        'next_actions',
        'created_by',
    ];

    protected $hidden = ['manager_private_note'];

    protected function casts(): array
    {
        return [
            'checkin_date' => DateOnly::class,
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'progress_status' => KpiHealth::class,
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
