<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IdCard extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const ISSUED = 'issued';

    public const SUSPENDED = 'suspended';

    public const REVOKED = 'revoked';

    public const PENDING_PRINT = 'pending_print';

    public const PRINTED = 'printed';

    public const LOST = 'lost';

    public const DAMAGED = 'damaged';

    public const EXPIRED = 'expired';

    public const REPLACED = 'replaced';

    /** A card still in the employee's hands or on its way to them (EUISIS one-live-card rule). */
    public const LIVE = [self::PENDING_PRINT, self::PRINTED, self::ISSUED, self::ACTIVE];

    /** A card that may be replaced by a new one (EUISIS ReplaceCardAction). */
    public const REPLACEABLE = [self::LOST, self::DAMAGED, self::REVOKED, self::EXPIRED, self::SUSPENDED];

    protected $guarded = ['id', 'live_current_employee_id'];

    /** Credential secrets never leave the Core. */
    protected $hidden = ['token_hash', 'qr_payload', 'qr_payload_hash', 'display_snapshot'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean', 'reprint_required' => 'boolean',
            'issued_at' => 'datetime', 'activated_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime',
            'printed_at' => 'datetime', 'reprint_reasons' => 'array', 'display_snapshot' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CardRequest::class, 'card_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function previousCard(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_card_id');
    }

    /** The card issued in place of this one. */
    public function replacementCard(): HasOne
    {
        return $this->hasOne(self::class, 'previous_card_id');
    }

    /** The shared, machine-readable reason a credential cannot be accepted. */
    public function invalidReason(): ?string
    {
        if ($this->employee === null || $this->employee->status !== Employee::ACTIVE) {
            return 'EMPLOYEE_INACTIVE';
        }
        if ($this->revoked_at !== null || $this->status === self::REVOKED) {
            return 'CARD_REVOKED';
        }
        if ($this->status === self::REPLACED || ! $this->is_current) {
            return 'CARD_REPLACED';
        }
        if ($this->status === self::EXPIRED || ($this->expires_at !== null && ! $this->expires_at->isFuture())) {
            return 'CARD_EXPIRED';
        }
        if ($this->status === self::SUSPENDED) {
            return 'CARD_SUSPENDED';
        }
        if ($this->status !== self::ACTIVE) {
            return 'CARD_INACTIVE';
        }
        if (($this->qr_status ?? 'active') !== 'active') {
            return 'CREDENTIAL_INVALID';
        }

        $assignment = $this->employee->currentAssignment;
        if ($assignment === null || $assignment->employee_id !== $this->employee_id || ! $assignment->isEffective()) {
            return 'ASSIGNMENT_INACTIVE';
        }

        return null;
    }

    public function isValidCredential(): bool
    {
        return $this->invalidReason() === null;
    }
}
