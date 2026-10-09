<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceFeedbackStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeServiceFeedback extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_service_feedback';

    protected $fillable = [
        'employee_id',
        'employee_feedback_token_id',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'position_service_id',
        'service_no_snapshot',
        'service_name_snapshot',
        'service_no_snapshot',
        'service_name_snapshot',
        'rating',
        'comment',
        'client_name',
        'client_contact',
        'ip_address',
        'user_agent',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    /**
     * Submission metadata is kept for abuse investigation only. Hiding it here
     * keeps it out of any Inertia payload built by serialising the model, so a
     * client's IP never reaches a browser by accident.
     */
    protected $hidden = [
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceFeedbackStatus::class,
            'rating' => 'integer',
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

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The service delivered, from the position's own catalog.
     *
     * NOT ServiceType — that model lists entitlements an employee receives,
     * which is unrelated to the work a client rates here.
     */
    public function positionService(): BelongsTo
    {
        return $this->belongsTo(PositionService::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(EmployeeFeedbackToken::class, 'employee_feedback_token_id');
    }

    /** Entries an employee or a report may surface; excludes suppressed comments. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', ServiceFeedbackStatus::Hidden->value);
    }

    public function scopeLowRated(Builder $query, int $threshold = 2): Builder
    {
        return $query->where('rating', '<=', $threshold);
    }
}
