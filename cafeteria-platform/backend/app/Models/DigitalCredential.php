<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The credential a card's QR carries (ADR 0010), apart from the card: it is
 * issued, superseded, revoked or expired on its own. Only its keyed hash is
 * looked up; the token is kept encrypted and never serialized.
 */
class DigitalCredential extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUPERSEDED = 'superseded';

    public const REVOKED = 'revoked';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_verified_at' => 'datetime', 'key_version' => 'integer'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'id_card_id');
    }
}
