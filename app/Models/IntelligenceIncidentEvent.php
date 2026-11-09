<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable step of an incident's timeline: detected, status changed, owner set, recommendation created, verified… */
class IntelligenceIncidentEvent extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const EVENTS = ['detected', 'recurred', 'escalated', 'status_changed', 'owner_changed', 'recommendation_created', 'ai_explained', 'mitigated', 'resolved', 'verified', 'closed', 'note'];

    protected $fillable = ['incident_id', 'event', 'from_value', 'to_value', 'note', 'payload', 'actor_user_id', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IntelligenceIncident::class, 'incident_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
