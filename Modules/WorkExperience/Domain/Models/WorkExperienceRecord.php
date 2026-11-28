<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an employee's work experience as the office recorded it. With
 * employee_assignment_id it gives the details of that assignment (salary, a
 * corrected title or dates); without it, it is a line the office wrote itself:
 * service before the system, or service in another institution.
 */
class WorkExperienceRecord extends Model
{
    use HasUuidPrimaryKey;

    public const KIND_INTERNAL = 'internal';

    public const KIND_EXTERNAL = 'external';

    protected $table = 'work_experience_records';

    protected $fillable = [
        'employee_id', 'employee_assignment_id', 'kind', 'employer_name', 'position_title', 'unit_name',
        'started_on', 'ended_on', 'monthly_salary', 'note', 'evidence_disk', 'evidence_path', 'evidence_name',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date:Y-m-d',
            'ended_on' => 'date:Y-m-d',
            'monthly_salary' => 'decimal:2',
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
}
