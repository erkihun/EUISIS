<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Grievance\Domain\Enums\GrievanceTaskStatus;
use Modules\Grievance\Domain\Enums\GrievanceTaskType;

/**
 * A lightweight case task (collect a response, schedule a hearing, prepare a decision…).
 */
class GrievanceTask extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_tasks';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'task_type',
        'title',
        'assigned_to_user_id',
        'due_at',
        'status',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'task_type' => GrievanceTaskType::class,
            'status' => GrievanceTaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
