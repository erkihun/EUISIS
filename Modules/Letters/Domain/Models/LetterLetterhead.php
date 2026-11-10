<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One letter layout of an institution (a "header and footer"): page setup,
 * the header and footer composed as rows of text and images (structure in
 * Modules\Letters\Application\Services\LetterLayout), the images it uses and
 * the official stamp. An institution may keep several; one is its default.
 * `organization_id` null is a platform layout offered to institutions that
 * have none of their own.
 */
class LetterLetterhead extends Model
{
    use HasUuidPrimaryKey;

    public const DISK = 'local';

    protected $table = 'letter_letterheads';

    protected $fillable = ['organization_id', 'name', 'is_default', 'active', 'page', 'header', 'footer', 'images', 'stamp_path', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_default' => 'bool', 'active' => 'bool', 'page' => 'array', 'header' => 'array', 'footer' => 'array', 'images' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function letters(): HasMany
    {
        return $this->hasMany(Letter::class, 'letterhead_id');
    }

    public function isPlatform(): bool
    {
        return $this->organization_id === null;
    }

    /** @return list<array{key: string, path: string, name: string}> */
    public function imageList(): array
    {
        return array_values(array_filter((array) ($this->images ?? []), fn ($i): bool => is_array($i) && isset($i['key'], $i['path'])));
    }

    /** @return list<string> */
    public function imageKeys(): array
    {
        return array_map(fn (array $i): string => (string) $i['key'], $this->imageList());
    }
}
