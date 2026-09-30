<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceDispatchChannel;
use App\Enums\Grievance\GrievanceDispatchStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dispatch of a letter through a channel. `delivered` is set only when the channel confirms delivery.
 */
class GrievanceLetterDispatch extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_letter_dispatches';

    protected $fillable = [
        'letter_id',
        'recipient_id',
        'channel',
        'status',
        'destination',
        'provider_reference',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_at',
        'failure_reason',
        'acknowledged_at',
        'acknowledged_by',
        'dispatched_by',
    ];

    protected function casts(): array
    {
        return [
            'channel' => GrievanceDispatchChannel::class,
            'status' => GrievanceDispatchStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(GrievanceLetter::class, 'letter_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(GrievanceLetterRecipient::class, 'recipient_id');
    }
}
