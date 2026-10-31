<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PositionService;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Feedback\Contracts\Data\PublicFeedbackTargetType;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;

/**
 * A public submission (feedback, suggestion, complaint) as other modules may
 * read it: the Core's employee and position-service relations, Performance's
 * KPI actuals, the dashboard and the Situation Room counts. Who sent it and
 * where from are hidden, as on the owner's model; writes are refused. The
 * Feedback module records and changes submissions.
 */
final class ServiceFeedbackRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_service_feedback';

    protected $hidden = [
        'ip_address',
        'user_agent',
        'client_name',
        'client_contact',
        'client_email',
        'submitter_organization',
        'submitter_address',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceFeedbackStatus::class,
            'type' => ServiceFeedbackType::class,
            'target_type' => PublicFeedbackTargetType::class,
            'rating' => 'integer',
            'incident_date' => 'date:Y-m-d',
            'service_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function positionService(): BelongsTo
    {
        return $this->belongsTo(PositionService::class);
    }

    /** Entries an employee or a report may surface; excludes suppressed comments. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', ServiceFeedbackStatus::Hidden->value);
    }

    /** The name stored in polymorphic columns, as for the Feedback module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\EmployeeServiceFeedback';
    }
}
