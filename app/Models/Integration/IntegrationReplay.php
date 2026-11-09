<?php

declare(strict_types=1);

namespace App\Models\Integration;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit record of a webhook replay: who, which subscription, what criteria, why, result. */
class IntegrationReplay extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['webhook_subscription_id', 'initiated_by_user_id', 'initiated_by_application_id', 'criteria', 'reason', 'event_count', 'status'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'event_count' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
