<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IdCard extends Model
{
    use HasUuidPrimaryKey;

    protected $hidden = ['token_hash', 'qr_payload'];

    protected $fillable = [
        'employee_id',
        'card_request_id',
        'previous_card_id',
        'print_batch_item_id',
        'card_number',
        'status',
        'token_hash',
        'token_version',
        'token_last_rotated_at',
        'printed_at',
        'issued_at',
        'activated_at',
        'expires_at',
        'revoked_at',
        'revoke_reason',
        'display_snapshot',
        'notes',
        'is_current',
        'qr_payload',
        'qr_payload_version',
        'public_card_uuid',
        'qr_status',
        'qr_issued_at',
        'qr_rotated_at',
        // Which QR symbol the card was last rendered with. Metadata only —
        // never used to resolve a scan.
        'qr_model',
        'qr_version',
        'qr_error_correction',
        'qr_payload_hash',
        'reprint_required',
        'reprint_reasons',
        'reprint_required_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CardStatus::class,
            'reprint_required' => 'boolean',
            'reprint_reasons' => 'array',
            'reprint_required_at' => 'datetime',
            'token_version' => 'integer',
            'token_last_rotated_at' => 'datetime',
            'printed_at' => 'datetime',
            'issued_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'display_snapshot' => 'array',
            'is_current' => 'bool',
            'qr_payload' => 'encrypted',
            'qr_payload_version' => 'integer',
            'qr_issued_at' => 'datetime',
            'qr_rotated_at' => 'datetime',
            'qr_model' => 'integer',
            'qr_version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function nfcCredentials(): HasMany
    {
        return $this->hasMany(NfcCredential::class);
    }

    public function cardRequest(): BelongsTo
    {
        return $this->belongsTo(CardRequest::class);
    }

    public function previousCard(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_card_id');
    }

    public function replacementCard(): HasOne
    {
        return $this->hasOne(self::class, 'previous_card_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(CardVerification::class, 'id_card_id');
    }

    public function issuance(): HasOne
    {
        return $this->hasOne(CardIssuance::class, 'id_card_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(CardReplacement::class, 'old_card_id');
    }
}
