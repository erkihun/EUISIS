<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublicAnnouncementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A general notice published on the public website.
 *
 * Distinct from TransferAnnouncement, which is a business entity with its own
 * application workflow and keeps its own public routes.
 */
class PublicAnnouncement extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'slug', 'title_en', 'title_am', 'summary_en', 'summary_am', 'content_en', 'content_am',
        'category', 'featured_image_path', 'status', 'published_at', 'expires_at', 'is_featured',
        'sort_order', 'created_by', 'updated_by', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublicAnnouncementStatus::class,
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The single definition of "publicly visible".
     *
     * Published or Scheduled, publication time reached, and not yet expired.
     * A scheduled item goes live by this condition alone — no cron — and an
     * expired one drops out the same way. Soft-deleted rows are excluded by
     * the SoftDeletes scope.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('status', PublicAnnouncementStatus::publicStatuses())
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isPubliclyVisible(): bool
    {
        return in_array($this->status?->value, PublicAnnouncementStatus::publicStatuses(), true)
            && $this->published_at !== null
            && $this->published_at->lte(now())
            && ($this->expires_at === null || $this->expires_at->gt(now()))
            && ! $this->trashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PublicAnnouncementAttachment::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
