<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models\Concerns;

/**
 * A landing row's Amharic text, kept beside its English columns in
 * `translations` as {"am": {field: text}}. The public site shows a field in
 * the visitor's language and falls back to the English column when the
 * Amharic one is empty.
 */
trait HasAmharicText
{
    public function initializeHasAmharicText(): void
    {
        $this->mergeCasts(['translations' => 'array']);
        $this->mergeFillable(['translations']);
    }

    /** The field in `$locale` ('en' or 'am'), its English column when the Amharic is empty. */
    public function localized(string $field, string $locale): mixed
    {
        if ($locale === 'am') {
            $value = data_get($this->translations, "am.{$field}");
            if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                return $value;
            }
        }

        return $this->getAttribute($field);
    }

    /** Whether the row has Amharic text for `$field`. */
    public function hasAmharic(string $field): bool
    {
        $value = data_get($this->translations, "am.{$field}");

        return is_array($value) ? $value !== [] : trim((string) $value) !== '';
    }

    /**
     * The Amharic text of `$fields` for the landing manager's form, '' (or [] for a list) when unset.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function amharicFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = data_get($this->translations, "am.{$field}");
            $out[$field] = is_array($value) ? array_values($value) : (string) ($value ?? '');
        }

        return $out;
    }
}
