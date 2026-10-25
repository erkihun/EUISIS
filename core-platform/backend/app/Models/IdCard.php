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

    protected $guarded = ['id', 'live_current_employee_id'];

    /** Credential secrets never leave the Core. */
    protected $hidden = ['token_hash', 'qr_payload', 'qr_payload_hash', 'display_snapshot'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean', 'reprint_required' => 'boolean',
            'issued_at' => 'datetime', 'activated_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime',
            'printed_at' => 'datetime', 'reprint_reasons' => 'array',
        ];
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
