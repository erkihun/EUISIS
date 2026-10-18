<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\RichText\RichText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a formatted-text field by what a reader sees, not by its markup.
 *
 *   'body' => [RichTextContent::required(maxLength: 5000)]
 *   'note' => ['nullable', new RichTextContent(maxLength: 2000)]
 *
 * - required(): `<p><br></p>` and `<p>&nbsp;</p>` count as empty, which
 *   Laravel's `required` lets through.
 * - maxLength: characters of plain text, so Amharic counts per character
 *   and formatting does not eat into the limit.
 * - The markup itself is capped (config richtext.max_html_bytes) so a
 *   short text wrapped in a huge pile of tags cannot fill the column.
 *
 * Validation never makes HTML safe; the model cast (RichTextHtml)
 * sanitizes on save.
 */
final class RichTextContent implements ValidationRule
{
    public bool $implicit = false;

    public function __construct(private readonly ?int $maxLength = null) {}

    public static function required(?int $maxLength = null): self
    {
        $rule = new self($maxLength);
        $rule->implicit = true;

        return $rule;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && ! is_string($value)) {
            $fail('richtext.invalid')->translate();

            return;
        }

        if (RichText::isEmpty($value)) {
            if ($this->implicit) {
                $fail('richtext.required')->translate();
            }

            return;
        }

        $maxBytes = (int) config('richtext.max_html_bytes');
        if (strlen((string) $value) > $maxBytes) {
            $fail('richtext.too_much_formatting')->translate();

            return;
        }

        $max = $this->maxLength ?? (int) config('richtext.max_plain_length');
        if (RichText::plainLength($value) > $max) {
            $fail('richtext.max')->translate(['max' => number_format($max)]);
        }
    }
}
