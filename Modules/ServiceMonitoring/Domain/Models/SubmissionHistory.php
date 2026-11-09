<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\SubmissionHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only status history of a submission: rows are never updated or deleted. */
class SubmissionHistory extends Model
{
    /** @use HasFactory<SubmissionHistoryFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_submission_histories';

    protected $fillable = [
        'submission_id',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SubmissionHistoryFactory
    {
        return SubmissionHistoryFactory::new();
    }

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Submission history is append-only.'));
        static::deleting(fn (): never => throw new LogicException('Submission history is append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
