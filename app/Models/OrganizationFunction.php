<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A function the organization performs under a mandate: main functions and
 * their sub-functions. `path` holds the ancestor ids joined by '/', ending in
 * this id, so a subtree is one LIKE query and cycles are a string test.
 */
class OrganizationFunction extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'mandate_id', 'power_id', 'parent_function_id', 'code', 'title_en', 'title_am',
        'description_en', 'description_am', 'legal_reference', 'sequence', 'path', 'depth',
        'effective_from', 'effective_to', 'status', 'version_no', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'depth' => 'integer', 'version_no' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(InstitutionMandate::class, 'mandate_id');
    }

    public function power(): BelongsTo
    {
        return $this->belongsTo(OrganizationPower::class, 'power_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_function_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_function_id')->orderBy('sequence');
    }

    /** This function and everything under it. */
    public function scopeSubtreeOf(Builder $query, self $root): Builder
    {
        return $query->where('path', 'like', $root->path.'%')
            ->where(fn (Builder $q) => $q->where('path', $root->path)->orWhere('path', 'like', $root->path.'/%'));
    }

    public function isAncestorOf(self $other): bool
    {
        return $other->path === $this->path || str_starts_with($other->path, $this->path.'/');
    }
}
