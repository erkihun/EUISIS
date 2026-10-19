<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable step of a support ticket's timeline: created, status changed, assigned, note. */
class SupportTicketEvent extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    public const EVENTS = ['created', 'status_changed', 'assigned', 'note'];

    protected $fillable = ['ticket_id', 'event', 'from_value', 'to_value', 'note', 'actor_user_id', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
