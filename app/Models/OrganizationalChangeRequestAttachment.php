<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationalChangeAttachmentType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supporting document. Files live on the private disk, never public. */
class OrganizationalChangeRequestAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organizational_change_request_attachments';

    protected $fillable = [
        'request_id',
        'document_type',
        'reference_no',
        'document_date',
        'original_name',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => OrganizationalChangeAttachmentType::class,
            'document_date' => 'date',
            'file_size' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OrganizationalChangeRequest::class, 'request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
