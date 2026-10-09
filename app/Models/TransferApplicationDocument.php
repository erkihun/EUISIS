<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferDocumentVerificationStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferApplicationDocument extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'transfer_application_id',
        'document_type',
        'original_name',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'verification_status',
        'verification_remark',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verification_status' => TransferDocumentVerificationStatus::class,
            'verified_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function transferApplication(): BelongsTo
    {
        return $this->belongsTo(TransferApplication::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** All documents for this application are verified. */
    public static function allVerified(string $applicationId): bool
    {
        return ! self::query()
            ->where('transfer_application_id', $applicationId)
            ->where('verification_status', '!=', TransferDocumentVerificationStatus::Verified->value)
            ->exists();
    }
}
