<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationType extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'prefix',
        'name_en',
        'name_am',
        'description',
        'description_en',
        'description_am',
        'is_active',
        'sort_order',
        'level_order',
        'category',
        'parent_allowed_types',
        'is_demo',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'bool',
            'is_active' => 'bool',
            'level_order' => 'integer',
            'parent_allowed_types' => 'array',
        ];
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Returns true if this type allows $parentType as a valid parent.
     * - null parent_allowed_types  → no restriction (backwards compat)
     * - empty array                → root type, no parent permitted
     * - non-empty array            → parent code must be in the list
     */
    public function allowsParentType(self $parentType): bool
    {
        $allowed = $this->parent_allowed_types;

        if ($allowed === null) {
            return true;
        }

        return in_array($parentType->code, $allowed, true);
    }

    protected function prefix(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : mb_strtoupper(trim($value), 'UTF-8'),
        );
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : mb_strtoupper(trim($value), 'UTF-8'),
        );
    }
}
