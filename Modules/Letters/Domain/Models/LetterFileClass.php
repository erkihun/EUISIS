<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of an institution's file classification scheme (ISO 15489,
 * Malaysia's DDMS prerequisite): a code and bilingual title, optionally under
 * a parent entry. A null organization is the platform scheme offered to every
 * institution without its own.
 */
class LetterFileClass extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'letter_file_classes';

    protected $fillable = ['organization_id', 'parent_id', 'code', 'title_am', 'title_en', 'active', 'position', 'updated_by'];

    protected function casts(): array
    {
        return ['active' => 'bool', 'position' => 'int'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('code');
    }

    public function label(string $locale): string
    {
        $title = $locale === 'am' ? ($this->title_am ?? $this->title_en) : ($this->title_en ?? $this->title_am);

        return $title !== null && $title !== '' ? "{$this->code} · {$title}" : $this->code;
    }
}
