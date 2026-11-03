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

    /** The category a known type starts with, by Amharic name; it stays editable on the type. */
    public const DEFAULT_CATEGORIES = [
        'ከተማ አስተዳደር' => 'root',
        'የከንቲባ ጽህፈት ቤት' => 'functional',
        'ጽህፈት ቤት' => 'functional',
        'ቢሮ' => 'functional',
        'ኤጀንሲ' => 'functional',
        'ማዕከል' => 'functional',
        'ተቋም' => 'functional',
        'ዘርፍ' => 'functional',
        'ዳይሬክቶሬት' => 'functional',
        'ዲፓርትመንት' => 'functional',
        'ቡድን' => 'functional',
        'ፑል' => 'functional',
        'ክፍለ ከተማ' => 'geographic',
        'ወረዳ' => 'geographic',
        'ቅርንጫፍ' => 'geographic',
        'አገልግሎት ሰጪ' => 'service_provider',
        'ምክር ቤት' => 'independent',
        'ኮሚሽን' => 'independent',
        'ባለሥልጣን' => 'independent',
        'ኮርፖሬሽን' => 'independent',
        'የልማት ድርጅት' => 'independent',
        'ሆልዲንግስ' => 'independent',
    ];

    /** Types the owner abbreviates instead of taking the first letter of the name. */
    public const PREFIX_EXCEPTIONS = [
        'ከተማ አስተዳደር' => 'ከማ',
        'ምክር ቤት' => 'ምቤ',
        'የልማት ድርጅት' => 'ልድ',
        'አገልግሎት ሰጪ' => 'አሰ',
        'ኮሚሽን' => 'ኮሚ',
        'ኮርፖሬሽን' => 'ኮር',
    ];

    /** The code prefix of a type: the first letter of its Amharic name, unless it has its own abbreviation. */
    public static function prefixFromName(?string $nameAm): ?string
    {
        $name = trim((string) $nameAm);
        if (isset(self::PREFIX_EXCEPTIONS[$name])) {
            return self::PREFIX_EXCEPTIONS[$name];
        }

        $letter = mb_substr($name, 0, 1, 'UTF-8');

        return preg_match('/^\p{Ethiopic}$/u', $letter) === 1 ? $letter : null;
    }

    /** Named rather than coded: a type's code is a generated number, not a stable meaning. */
    public function isSubCity(): bool
    {
        return $this->code === 'SUB_CITY' || $this->name_am === 'ክፍለ ከተማ';
    }

    public function isWoreda(): bool
    {
        return $this->code === 'WOREDA' || $this->name_am === 'ወረዳ';
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
