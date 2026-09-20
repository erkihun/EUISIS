<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicAnnouncementAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['public_announcement_id', 'path', 'original_name', 'mime', 'size', 'sort_order'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'sort_order' => 'integer'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(PublicAnnouncement::class, 'public_announcement_id');
    }
}
