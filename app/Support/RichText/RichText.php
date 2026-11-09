<?php

declare(strict_types=1);

namespace App\Support\RichText;

/**
 * Pure helpers for formatted text: what a reader actually sees. Used by
 * validation (is it empty, how long is it), by exports that need plain
 * text (CSV, Excel, search) and by the sanitizer to tell stored HTML from
 * a legacy plain-text value. None of these makes markup safe to output;
 * RichTextSanitizer does that.
 */
final class RichText
{
    private const BLOCK_END = '~</(p|h[1-6]|li|blockquote|tr|div|ul|ol|table|thead|tbody)\s*>~i';

    private const CELL_END = '~</(td|th)\s*>~i';

    private const LINE_BREAK = '~<br\s*/?>~i';

    /** Whether the value is markup rather than a plain-text (legacy) value. */
    public static function isHtml(?string $value): bool
    {
        return $value !== null && preg_match('~</?[a-z][a-z0-9]*(\s[^<>]*)?/?>~i', $value) === 1;
    }

    /**
     * The words a reader sees, one block per line. `<p><br></p>`,
     * `<p>&nbsp;</p>` and an empty table are all "" here.
     */
    public static function plainText(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! self::isHtml($value)) {
            return self::tidyLines(str_replace(["\r\n", "\r"], "\n", $value), maxBlankLines: 1);
        }

        $text = preg_replace(self::LINE_BREAK, "\n", $value) ?? $value;
        $text = preg_replace(self::CELL_END, "\t", $text) ?? $text;
        $text = preg_replace(self::BLOCK_END, "\n", $text) ?? $text;
        // Script and style bodies are code, never reading text.
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // One line per block: nested blocks (a list inside a quote, a
        // paragraph inside a cell) must not leave blank lines behind.
        return self::tidyLines($text, maxBlankLines: 0);
    }

    /** Characters the reader sees, counted as characters (Ethiopic safe). */
    public static function plainLength(?string $value): int
    {
        return mb_strlen(self::plainText($value), 'UTF-8');
    }

    /** True when there is nothing to read: null, blank, or empty markup. */
    public static function isEmpty(?string $value): bool
    {
        return self::plainText($value) === '';
    }

    /**
     * A plain-text value as markup: blank lines separate paragraphs, single
     * newlines become line breaks, every character escaped.
     */
    public static function fromPlainText(string $value): string
    {
        $paragraphs = preg_split('~\n\s*\n~', trim(str_replace(["\r\n", "\r"], "\n", $value))) ?: [];

        return implode('', array_map(
            static fn (string $paragraph): string => '<p>'.str_replace("\n", '<br>', htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')).'</p>',
            array_filter($paragraphs, static fn (string $paragraph): bool => trim($paragraph) !== ''),
        ));
    }

    private static function tidyLines(string $text, int $maxBlankLines): string
    {
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], [' ', '', ''], $text);
        $lines = array_map(static fn (string $line): string => trim(preg_replace('~[ \t]+~u', ' ', $line) ?? $line), explode("\n", $text));
        $text = preg_replace('~\n{'.($maxBlankLines + 2).',}~', str_repeat("\n", $maxBlankLines + 1), implode("\n", $lines)) ?? '';

        return trim($text);
    }
}
