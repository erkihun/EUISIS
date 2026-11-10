<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Letters\Domain\Enums\AttachmentKind;

/** A file on the private disk: the scanned original, an annex, or the PDF rendered at dispatch. */
class LetterAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'letter_attachments';

    protected $fillable = ['letter_id', 'kind', 'disk', 'path', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by'];

    protected function casts(): array
    {
        return ['kind' => AttachmentKind::class, 'size' => 'int'];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
