<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A published, immutable version of a template. Letters record the version they were composed from. */
class LetterTemplateVersion extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'letter_template_versions';

    protected $fillable = ['template_id', 'version_no', 'subject', 'body', 'placeholders', 'change_note', 'published_by', 'published_at', 'created_at'];

    protected function casts(): array
    {
        return ['version_no' => 'int', 'placeholders' => 'array', 'published_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'template_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return list<array{key: string, label_en: string, label_am: string|null, required: bool}> */
    public function placeholderList(): array
    {
        return array_values(array_map(static fn (array $p): array => [
            'key' => (string) ($p['key'] ?? ''),
            'label_en' => (string) ($p['label_en'] ?? $p['key'] ?? ''),
            'label_am' => isset($p['label_am']) ? (string) $p['label_am'] : null,
            'required' => (bool) ($p['required'] ?? false),
        ], (array) ($this->placeholders ?? [])));
    }
}
