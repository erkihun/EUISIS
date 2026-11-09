<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\NonSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A woreda formally recorded as "not submitted" for a period, so its sub-city can certify (D8). */
class NonSubmission extends Model
{
    /** @use HasFactory<NonSubmissionFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_non_submissions';

    protected $fillable = [
        'period_id',
        'participant_id',
        'reason',
        'recorded_by',
    ];

    protected static function newFactory(): NonSubmissionFactory
    {
        return NonSubmissionFactory::new();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'period_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
