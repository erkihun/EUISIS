<?php

declare(strict_types=1);

namespace App\Models\Communication;

use App\Enums\Communication\AnnouncementTargetType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One audience of an announcement (stored once; matched per reader). */
class AnnouncementTarget extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['target_type' => AnnouncementTargetType::class];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
