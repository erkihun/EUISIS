<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\SupportAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A support officer (ደጋፊ ባለሙያ) assigned to a participant (D22). */
class SupportAssignment extends Model
{
    /** @use HasFactory<SupportAssignmentFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_support_assignments';

    protected $fillable = [
        'participant_id',
        'user_id',
        'assigned_by',
    ];

    protected static function newFactory(): SupportAssignmentFactory
    {
        return SupportAssignmentFactory::new();
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
