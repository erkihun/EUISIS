<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time ticket that signs an employee into the applicant portal when they
 * open it from the EUISIS employee portal. Only the hash is stored.
 */
class ApplicantSignInTicket extends Model
{
    protected $table = 'court_applicant_sign_in_tickets';

    public const LIFETIME_SECONDS = 120;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
