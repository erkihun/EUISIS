<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\RichText\RichTextSanitizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A formatted-text column. Sanitized on every write, whoever writes it
 * (form, import, seeder, API), so nothing unsafe reaches the database.
 * Empty markup is stored as NULL.
 *
 * Read as stored. Rows saved before the column became formatted keep their
 * plain text; RichTextViewer and RichText::plainText() handle both shapes,
 * so no migration rewrites existing data.
 */
final class RichTextHtml implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return app(RichTextSanitizer::class)->sanitize($value === null ? null : (string) $value);
    }
}
