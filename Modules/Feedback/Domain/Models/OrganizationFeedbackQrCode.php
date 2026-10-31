<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Feedback\Domain\Enums\OrganizationFeedbackQrStatus;

/**
 * An organization's public feedback QR identity.
 *
 * One active row per organization (partial unique index). The 64-hex token is
 * the only thing the printed QR carries; it is never a sequential ID, never
 * reused after revocation, and withheld from JSON so it cannot leak through a
 * serialised model. Pages that print it read `$qr->public_token` on purpose.
 *
 * Issued, rotated and disabled by the module's OrganizationFeedbackQrService.
 */
class OrganizationFeedbackQrCode extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organization_feedback_qr_codes';

    protected $fillable = [
        'organization_id',
        'public_token',
        'status',
        'enabled_at',
        'disabled_at',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'rotated_from_id',
        'last_scanned_at',
        'scan_count',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'public_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationFeedbackQrStatus::class,
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_scanned_at' => 'datetime',
            'scan_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function acceptsFeedback(): bool
    {
        return $this->status->acceptsFeedback();
    }

    /**
     * The absolute URL encoded into the printed QR. Built from the path, not
     * a route name, so the printed sheet is valid as soon as the public page
     * for organization targets is mounted at /feedback/{token}.
     */
    public function publicUrl(): string
    {
        return url('/feedback/'.$this->public_token);
    }
}
