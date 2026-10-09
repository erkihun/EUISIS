<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceCorrectiveActionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An institutional corrective action ordered by a decision. Not a disciplinary measure.
 */
class GrievanceCorrectiveAction extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_corrective_actions';

    protected $fillable = [
        'grievance_id',
        'decision_id',
        'description',
        'responsible_organization_id',
        'responsible_organization_unit_id',
        'due_date',
        'status',
        'completion_notes',
        'completion_evidence_id',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceCorrectiveActionStatus::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(GrievanceDecision::class, 'decision_id');
    }

    public function responsibleOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'responsible_organization_id');
    }

    public function responsibleUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'responsible_organization_unit_id');
    }
}
