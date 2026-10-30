<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\ConsolidationSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\ServiceMonitoring\Domain\Enums\SnapshotKind;

/** An immutable consolidation (D24), stored at sub-city certification and at period lock. Locked reports read only from these. */
class ConsolidationSnapshot extends Model
{
    /** @use HasFactory<ConsolidationSnapshotFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_consolidation_snapshots';

    protected $fillable = [
        'period_id',
        'kind',
        'participant_id',
        'payload',
        'checksum',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SnapshotKind::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ConsolidationSnapshotFactory
    {
        return ConsolidationSnapshotFactory::new();
    }

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Consolidation snapshots are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Consolidation snapshots are immutable.'));
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'period_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** sha256 of the canonical JSON payload. */
    public static function checksumOf(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function isIntact(): bool
    {
        return hash_equals($this->checksum, self::checksumOf($this->payload ?? []));
    }
}
