<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceMonitoring\SubmissionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One institution's report for one form and period.
 *
 * @property string $id
 * @property string $form_id
 * @property string $form_code
 * @property string $period_id
 * @property string $organization_id
 * @property SubmissionStatus $status
 * @property array<string, mixed> $data
 */
class ServiceMonitoringSubmission extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'form_id',
        'form_code',
        'period_id',
        'organization_id',
        'status',
        'data',
        'attachment_path',
        'attachment_name',
        'review_note',
        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'data' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(ServiceMonitoringForm::class, 'form_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ServiceMonitoringPeriod::class, 'period_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
