<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Communication\Domain\Enums\AnnouncementTargetType;

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
