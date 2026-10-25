<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * The one validity rule: active, current, QR active, not revoked, not expired,
     * and held by an employee whose employment has not ended.
     */
    public function isValidCredential(): bool
    {
        return $this->status === self::ACTIVE
            && $this->is_current
            && ($this->qr_status ?? 'active') === 'active'
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->employee !== null
            && ! $this->employee->isSeparated();
    }
}
