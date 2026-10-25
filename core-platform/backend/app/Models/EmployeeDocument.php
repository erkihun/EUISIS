<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored version of an employee's identity evidence: a document, the
 * photo or the signature (ADR 0009). The storage path is never serialized;
 * files are read only through the authorized download.
 */
class EmployeeDocument extends Model
{
    use HasUuids;

    public const DOCUMENT = 'document';

    public const PHOTO = 'photo';

    public const SIGNATURE = 'signature';

    public const KINDS = [self::DOCUMENT, self::PHOTO, self::SIGNATURE];

    /** The Core's identity and employment documents. */
    public const DOCUMENT_TYPES = ['appointment_letter', 'employment_letter', 'transfer_letter', 'identity_document', 'supporting_hr_document', 'other'];

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    /** An earlier version, kept as history. */
    public const REPLACED = 'replaced';

    public const ARCHIVED = 'archived';

    protected $guarded = ['id'];

    protected $hidden = ['storage_disk', 'storage_path', 'current_lineage_id', 'current_identity_slot'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean', 'version' => 'integer', 'file_size' => 'integer', 'width' => 'integer', 'height' => 'integer',
            'issued_at' => 'date', 'expires_at' => 'date', 'verified_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Verified and not past its expiry date. */
    public function isValid(): bool
    {
        return $this->status === self::VERIFIED && ($this->expires_at === null || $this->expires_at->gte(today()));
    }
}
