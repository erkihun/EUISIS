<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\PrefillValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A system figure shown beside a Section II input (D18), and the reason when the reported figure differs. */
class PrefillValue extends Model
{
    /** @use HasFactory<PrefillValueFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_prefill_values';

    protected $fillable = [
        'submission_id',
        'field',
        'system_value',
        'source',
        'notice',
        'captured_at',
        'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'system_value' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PrefillValueFactory
    {
        return PrefillValueFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
