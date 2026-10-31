<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Communication\Domain\Enums\AccessRequestStatus;
use Modules\Communication\Domain\Enums\AccessScopeType;

/**
 * A break-glass request to read private communication for an investigation.
 * It grants access only once approved by someone else, only inside its window,
 * and only to its named conversation or employee.
 */
class CommunicationAccessRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => AccessRequestStatus::class,
            'scope_type' => AccessScopeType::class,
            'decided_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /** Approved, not revoked, and now inside its window. */
    public function isInForce(): bool
    {
        return in_array($this->status, [AccessRequestStatus::Approved, AccessRequestStatus::Active], true)
            && $this->revoked_at === null
            && $this->starts_at !== null && $this->starts_at->lte(now())
            && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
