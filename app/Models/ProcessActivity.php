<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An activity of a process version (brief §20): nests under a parent
 * activity, carries tasks, typed I/O rows and, optionally, a work standard.
 * Belongs to one process version; a new version gets its own copy.
 */
class ProcessActivity extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'process_id', 'organization_id', 'parent_activity_id', 'code', 'name_en', 'name_am', 'description_en', 'description_am',
        'sequence', 'path', 'depth', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'depth' => 'integer', 'is_active' => 'boolean'];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_activity_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_activity_id')->orderBy('sequence')->orderBy('code');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProcessTask::class, 'activity_id')->orderBy('sequence')->orderBy('code');
    }

    public function ioRows(): MorphMany
    {
        return $this->morphMany(WorkItemIo::class, 'subject')->orderBy('sequence');
    }

    /** Standards that measure this activity as a whole (no task). */
    public function standards(): HasMany
    {
        return $this->hasMany(PositionServiceTaskStandard::class, 'activity_id')->orderByDesc('version_no');
    }

    /** This activity and everything under it. */
    public function scopeSubtreeOf(Builder $query, self $root): Builder
    {
        return $query->where('process_id', $root->process_id)
            ->where(fn (Builder $q) => $q->whereKey($root->getKey())->orWhere('path', 'like', $root->path.'/%'));
    }

    public function isAncestorOf(self $other): bool
    {
        return $other->getKey() !== $this->getKey() && str_starts_with($other->path, $this->path.'/');
    }
}
