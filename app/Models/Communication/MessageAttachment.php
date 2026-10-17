<?php

declare(strict_types=1);

namespace App\Models\Communication;

use App\Enums\Communication\AttachmentScanStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on private storage. Its path is never sent to the browser; it is
 * served by a controlled download that re-checks membership.
 */
class MessageAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path', 'storage_name'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
            'scan_status' => AttachmentScanStatus::class,
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
    }
}
