<?php

declare(strict_types=1);

namespace App\Models\Communication;

use App\Casts\RichTextHtml;
use App\Enums\Communication\AnnouncementPriority;
use App\Enums\Communication\AnnouncementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An official announcement — separate from chat, targeted, optionally acknowledged. */
class Announcement extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'priority' => AnnouncementPriority::class,
            'body' => RichTextHtml::class,
            'body_am' => RichTextHtml::class,
            'status' => AnnouncementStatus::class,
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
            'acknowledgement_required' => 'boolean',
            'target_count' => 'integer',
            'target_count_computed_at' => 'datetime',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AnnouncementTarget::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(AnnouncementReceipt::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AnnouncementAttachment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Published and not past its expiry — what a reader may see. */
    public function isLive(): bool
    {
        return $this->status === AnnouncementStatus::Published && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function localizedTitle(string $locale): string
    {
        return $locale === 'am' && filled($this->title_am) ? (string) $this->title_am : (string) $this->title;
    }

    public function localizedBody(string $locale): string
    {
        return $locale === 'am' && filled($this->body_am) ? (string) $this->body_am : (string) $this->body;
    }
}
