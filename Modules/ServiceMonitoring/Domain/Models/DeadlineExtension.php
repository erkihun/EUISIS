<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceMonitoring\DeadlineExtensionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A time-boxed extension of one participant's deadline for one period (D6). Revocable; never deleted. */
class DeadlineExtension extends Model
{
    /** @use HasFactory<DeadlineExtensionFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_extensions';

    protected $fillable = [
        'period_id',
        'participant_id',
        'due_at',
        'reason',
        'granted_by',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DeadlineExtensionFactory
    {
        return DeadlineExtensionFactory::new();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'period_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isActive(?CarbonImmutable $now = null): bool
    {
        return $this->revoked_at === null && $this->due_at->greaterThanOrEqualTo($now ?? CarbonImmutable::now());
    }
}
