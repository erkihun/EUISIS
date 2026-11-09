<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\CorrectionRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ServiceMonitoring\Domain\Enums\CorrectionStatus;

/** A request to correct an accepted submission before lock (D9). Approval creates revision n+1 as a draft. */
class CorrectionRequest extends Model
{
    /** @use HasFactory<CorrectionRequestFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_correction_requests';

    protected $fillable = [
        'submission_id',
        'requested_by',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'new_submission_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CorrectionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CorrectionRequestFactory
    {
        return CorrectionRequestFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function newSubmission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'new_submission_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
