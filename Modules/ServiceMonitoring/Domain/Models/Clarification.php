<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\ClarificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\ClarificationStatus;

/** A question from an institution to its support officers (D22): open → answered → closed. */
class Clarification extends Model
{
    /** @use HasFactory<ClarificationFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_clarifications';

    protected $fillable = [
        'participant_id',
        'period_id',
        'submission_id',
        'section',
        'subject',
        'status',
        'opened_by',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'section' => 'integer',
            'status' => ClarificationStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ClarificationFactory
    {
        return ClarificationFactory::new();
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'period_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ClarificationMessage::class)->orderBy('created_at');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
