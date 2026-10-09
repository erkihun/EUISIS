<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcVerificationLog extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'occurred_at' => 'datetime'];

    public function nfcCredential(): BelongsTo
    {
        return $this->belongsTo(NfcCredential::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(ServiceTerminal::class, 'terminal_id');
    }

    public function externalApplication(): BelongsTo
    {
        return $this->belongsTo(ExternalApplication::class, 'external_application_id');
    }
}
