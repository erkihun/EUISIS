<?php

declare(strict_types=1);

namespace App\Models\Communication;

use App\Enums\Communication\AttachmentScanStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file attached to an announcement; on private storage, served after an audience check. */
class AnnouncementAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path', 'storage_name'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'scan_status' => AttachmentScanStatus::class];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
