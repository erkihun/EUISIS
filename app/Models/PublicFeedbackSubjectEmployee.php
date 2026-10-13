<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee an internal review found involved in a public complaint
 * (brief §61). This is an investigation relation, not the complaint's
 * target: the QR target stays what the visitor scanned (§62).
 */
class PublicFeedbackSubjectEmployee extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['submission_id', 'employee_id', 'employee_assignment_id', 'subject_role', 'added_by', 'added_at'];

    protected function casts(): array
    {
        return ['added_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmployeeServiceFeedback::class, 'submission_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'employee_assignment_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
