<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NfcCredential extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * Never serialise the chip fingerprint or the key pointer to a client.
     * `key_version` stays visible: it is an opaque label, not a secret.
     */
    protected $hidden = ['chip_uid_hash', 'key_reference'];

    protected $casts = ['issued_at' => 'datetime', 'activated_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function idCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function verificationLogs(): HasMany
    {
        return $this->hasMany(NfcVerificationLog::class);
    }
}
