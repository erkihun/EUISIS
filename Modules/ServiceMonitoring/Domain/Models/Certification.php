<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\CertificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sub-city's certification of its consolidation for a period (D8), pointing at the snapshot taken then. */
class Certification extends Model
{
    /** @use HasFactory<CertificationFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_certifications';

    protected $fillable = [
        'period_id',
        'participant_id',
        'certified_by',
        'certified_at',
        'snapshot_id',
    ];

    protected function casts(): array
    {
        return [
            'certified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CertificationFactory
    {
        return CertificationFactory::new();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'period_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConsolidationSnapshot::class, 'snapshot_id');
    }

    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }
}
