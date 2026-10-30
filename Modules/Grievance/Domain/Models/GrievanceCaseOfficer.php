<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceCaseOfficerRole;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An officer explicitly assigned to a case at a Team/Directorate (organization unit) stage.
 */
class GrievanceCaseOfficer extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_case_officers';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'user_id',
        'employee_id',
        'role',
        'assigned_by',
        'assigned_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => GrievanceCaseOfficerRole::class,
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
