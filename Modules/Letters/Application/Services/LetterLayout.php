<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Support\Typography\DocumentFonts;

/**
 * The structure of a letter layout (a header and footer, ADR 0037 §13): page
 * setup, then header and footer as rows of up to three columns, each column
 * holding text lines and images with their own font, size, weight,
 * underline, colour and alignment. Nothing in a header or footer is fixed in
 * code: what prints is what the institution composed. Text may carry
 * placeholders filled per letter ({{institution.name}}, {{letter.number}},
 * {{page}} …). Stored as JSON on letter_letterheads and normalized here on
 * save and on read, so a malformed row can never reach the PDF.
 */
final class LetterLayout
{
    /** @var list<string> */
    public const PAPERS = ['a4', 'a5', 'letter', 'legal'];

    /** @var list<string> */
    public const ORIENTATIONS = ['portrait', 'landscape'];

    /**
     * Fonts that carry both Ethiopic and Latin (dompdf does not fall back per
     * glyph). Abyssinica SIL has no bold face; Noto Sans Ethiopic has one.
     * Neither ships an italic, so italic is not offered.
     *
     * @var array<string, array{family: string, fallback: string, normal: string, bold: string}>
     */
    public const FONTS = [
        'abyssinica' => ['family' => 'Abyssinica SIL', 'fallback' => "'DejaVu Serif', serif", 'normal' => 'AbyssinicaSIL-Regular.ttf', 'bold' => 'AbyssinicaSIL-Regular.ttf'],
        'noto_sans' => ['family' => 'Noto Sans Ethiopic', 'fallback' => "'DejaVu Sans', sans-serif", 'normal' => 'NotoSansEthiopic-Regular.ttf', 'bold' => 'NotoSansEthiopic-Bold.ttf'],
    ];

    /** @var list<string> */
    public const ALIGNS = ['left', 'center', 'right'];

    /** @var list<string> */
    public const VALIGNS = ['top', 'middle', 'bottom'];

    /**
     * Where the letter's number, and separately its date, print under the
     * header: at the left, the centre or the right edge, or not at all
     * ("none") when the layout prints that field in its header or footer.
     *
     * @var list<string>
     */
    public const REFERENCE_PLACES = ['left', 'center', 'right', 'none'];

    /** @var array<string, list<string>> the placeholders that stand in for a hidden number or date */
    public const REFERENCE_FIELDS = ['number' => ['letter.number'], 'date' => ['letter.date_ec', 'letter.date_gc']];

    /** Which letters a line prints on: every letter, Amharic letters only, English letters only. */
    public const LANGUAGES = ['both', 'am', 'en'];

    /** @var list<string> */
    public const BORDER_POSITIONS = ['none', 'top', 'bottom', 'both'];

    /** @var list<string> */
    public const BORDER_STYLES = ['thin', 'thick', 'double'];

    /** @var list<string> */
    public const ITEM_TYPES = ['text', 'image'];

    public const MAX_ROWS = 6;

    public const MAX_CELLS = 3;

    public const MAX_ITEMS = 8;

    public const MAX_IMAGES = 8;

    public const MAX_TEXT = 500;

    /** The image key that prints the organization record's own logo. */
    public const ORG_LOGO = 'org_logo';

    /** @var list<string> */
    public const PLACEHOLDERS = [
        'institution.name', 'institution.name_am', 'institution.name_en', 'institution.code', 'unit.name',
        'letter.number', 'letter.date_ec', 'letter.date_gc', 'today.date_ec', 'page',
    ];

    public const NAVY = '#122170';

    /**
     * A new layout before anyone edits it: emblem, the institution's names and
     * the unit in the header; institution, number and page in the footer. All
     * of it is ordinary rows the institution can change or delete.
     *
     * @return array{page: array<string, mixed>, header: array<string, mixed>, footer: array<string, mixed>}
     */
    public static function defaults(): array
    {
        return [
            'page' => self::page([]),
            'header' => ['rows' => [
                self::row([
                    self::cell([self::image(self::ORG_LOGO, 22)], 'left', 18),
                    self::cell([
                        self::text('{{institution.name_am}}', ['font_size' => 15, 'bold' => true, 'color' => self::NAVY]),
                        self::text('{{institution.name_en}}', ['font_size' => 11, 'color' => '#333333']),
                        self::text('{{unit.name}}', ['font_size' => 10, 'color' => '#444444']),
                    ], 'center', 64),
                    self::cell([], 'right', 18),
                ], ['border_position' => 'bottom', 'border_style' => 'thick', 'border_color' => self::NAVY]),
            ]],
            'footer' => ['rows' => [
                self::row([
                    self::cell([self::text('{{institution.name}} · {{letter.number}} · {{page}}', ['font_size' => 8.5, 'color' => '#555555'])], 'center', 0),
                ], ['border_position' => 'top', 'border_style' => 'thin', 'border_color' => '#C7CBE0']),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function page(array $in): array
    {
        // Layouts saved before the number and the date could be placed apart carry one side for both.
        $both = self::oneOf($in['reference_align'] ?? null, ['left', 'right'], 'left');

        return [
            'paper' => self::oneOf($in['paper'] ?? null, self::PAPERS, 'a4'),
            'orientation' => self::oneOf($in['orientation'] ?? null, self::ORIENTATIONS, 'portrait'),
            'margin_top' => self::number($in['margin_top'] ?? null, 5, 60, 18),
            'margin_right' => self::number($in['margin_right'] ?? null, 5, 50, 18),
            'margin_bottom' => self::number($in['margin_bottom'] ?? null, 10, 70, 30),
            'margin_left' => self::number($in['margin_left'] ?? null, 5, 50, 18),
            'font_family' => self::oneOf($in['font_family'] ?? null, array_keys(self::FONTS), 'abyssinica'),
            'font_size' => self::number($in['font_size'] ?? null, 8, 16, 11.5),
            'text_color' => self::color($in['text_color'] ?? null, '#111111'),
            'number_align' => self::oneOf($in['number_align'] ?? null, self::REFERENCE_PLACES, $both),
            'date_align' => self::oneOf($in['date_align'] ?? null, self::REFERENCE_PLACES, $both),
        ];
    }

    /**
     * Which of the number and the date the page set-up hides although no line
     * of the header or footer prints it: such a letter would go out without
     * its reference number or its date.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $footer
     * @return list<string> 'number' and/or 'date'
     */
    public static function hiddenReferences(array $page, array $header, array $footer): array
    {
        $text = '';
        foreach ([$header, $footer] as $section) {
            foreach ((array) ($section['rows'] ?? []) as $row) {
                foreach ((array) ($row['cells'] ?? []) as $cell) {
                    foreach ((array) ($cell['items'] ?? []) as $item) {
                        if (is_array($item) && ($item['type'] ?? 'text') === 'text') {
                            $text .= ' '.(string) ($item['text'] ?? '');
                        }
                    }
                }
            }
        }
        preg_match_all('/\{\{\s*([a-z_.]+)\s*\}\}/', $text, $matches);

        $missing = [];
        foreach (self::REFERENCE_FIELDS as $what => $fields) {
            if (($page[$what.'_align'] ?? 'left') === 'none' && array_intersect($fields, $matches[1]) === []) {
                $missing[] = $what;
            }
        }

        return $missing;
    }

    /**
     * Normalizes a header or footer: known keys only, values clamped, image
     * items that point at no stored image dropped.
     *
     * @param  array<string, mixed>|null  $in
     * @param  list<string>  $imageKeys
     * @return array{rows: list<array<string, mixed>>}
     */
    public static function section(?array $in, array $imageKeys): array
    {
        $rows = [];
        foreach (array_slice(array_values((array) ($in['rows'] ?? [])), 0, self::MAX_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = [];
            foreach (array_slice(array_values((array) ($row['cells'] ?? [])), 0, self::MAX_CELLS) as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $items = [];
                foreach (array_slice(array_values((array) ($cell['items'] ?? [])), 0, self::MAX_ITEMS) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $type = self::oneOf($item['type'] ?? null, self::ITEM_TYPES, 'text');
                    if ($type === 'image') {
                        $key = (string) ($item['image'] ?? '');
                        if ($key !== self::ORG_LOGO && ! in_array($key, $imageKeys, true)) {
                            continue;
                        }
                        $items[] = self::image($key, self::number($item['width_mm'] ?? null, 5, 120, 22), ! empty($item['full']));
                    } else {
                        $items[] = self::text(mb_substr((string) ($item['text'] ?? ''), 0, self::MAX_TEXT), $item);
                    }
                }
                $cells[] = self::cell($items, self::oneOf($cell['align'] ?? null, self::ALIGNS, 'center'), (int) self::number($cell['width'] ?? null, 0, 100, 0), self::oneOf($cell['valign'] ?? null, self::VALIGNS, 'middle'));
            }
            if ($cells !== []) {
                $rows[] = self::row($cells, $row);
            }
        }

        return ['rows' => $rows];
    }

    /** @return list<string> placeholders used in a text that the layout does not know */
    public static function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/u', $text, $matches);

        return array_values(array_unique(array_filter($matches[1], fn (string $key): bool => ! in_array($key, self::PLACEHOLDERS, true))));
    }

    /**
     * A line of text ready for the PDF: escaped, placeholders filled, line
     * breaks kept, {{page}} as the page counter.
     *
     * @param  array<string, string>  $vars
     */
    public static function fill(string $text, array $vars): string
    {
        $escaped = e($text);
        $filled = (string) preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', function (array $m) use ($vars): string {
            if ($m[1] === 'page') {
                return '<span class="lh-page"></span>';
            }

            return e($vars[$m[1]] ?? '');
        }, $escaped);

        return nl2br(trim($filled), false);
    }

    /** Whether a line prints in a letter of this language, and is not empty once filled. */
    public static function prints(array $item, string $language, array $vars): bool
    {
        if (! in_array($item['language'] ?? 'both', ['both', $language], true)) {
            return false;
        }
        if (($item['type'] ?? 'text') === 'image') {
            return true;
        }
        $text = (string) ($item['text'] ?? '');
        if (preg_match('/\{\{\s*page\s*\}\}/', $text) === 1) {
            return true;
        }
        // A line made only of placeholders that are empty for this letter (no unit, no number yet) and separators is skipped.
        $plain = (string) preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', fn (array $m): string => $vars[$m[1]] ?? '', $text);

        return trim((string) preg_replace('/[\s·|,\-–—\/:]+/u', '', $plain)) !== '';
    }

    /** `@font-face` rules for every layout font. */
    public static function fontCss(DocumentFonts $fonts): string
    {
        $rules = [];
        foreach (self::FONTS as $font) {
            foreach (['normal' => $font['normal'], 'bold' => $font['bold']] as $weight => $file) {
                $rules[] = sprintf('@font-face { font-family: "%s"; font-weight: %s; font-style: normal; src: url("%s") format("truetype"); }', $font['family'], $weight, $fonts->path($file));
                $rules[] = sprintf('@font-face { font-family: "%s"; font-weight: %s; font-style: italic; src: url("%s") format("truetype"); }', $font['family'], $weight, $fonts->path($file));
            }
        }

        return implode("\n", $rules);
    }

    public static function family(string $key): string
    {
        $font = self::FONTS[$key] ?? self::FONTS['abyssinica'];

        return '"'.$font['family'].'", '.$font['fallback'];
    }

    /** @return list<string> the image keys a layout's header and footer use */
    public static function usedImages(array $header, array $footer): array
    {
        return self::imageKeys($header, $footer, false);
    }

    /**
     * The images that print across the whole page width; they are kept larger than emblems.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $footer
     * @return list<string>
     */
    public static function wideImages(array $header, array $footer): array
    {
        return self::imageKeys($header, $footer, true);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $footer
     * @return list<string>
     */
    private static function imageKeys(array $header, array $footer, bool $wideOnly): array
    {
        $keys = [];
        foreach ([$header, $footer] as $section) {
            foreach ((array) ($section['rows'] ?? []) as $row) {
                foreach ((array) ($row['cells'] ?? []) as $cell) {
                    foreach ((array) ($cell['items'] ?? []) as $item) {
                        if (is_array($item) && ($item['type'] ?? '') === 'image' && (! $wideOnly || ! empty($item['full']))) {
                            $keys[] = (string) ($item['image'] ?? '');
                        }
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function row(array $cells, array $style = []): array
    {
        return [
            'cells' => $cells,
            'border_position' => self::oneOf($style['border_position'] ?? null, self::BORDER_POSITIONS, 'none'),
            'border_style' => self::oneOf($style['border_style'] ?? null, self::BORDER_STYLES, 'thin'),
            'border_color' => self::color($style['border_color'] ?? null, self::NAVY),
            'space_after' => self::number($style['space_after'] ?? null, 0, 30, 3),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function cell(array $items, string $align, int $width, string $valign = 'middle'): array
    {
        return ['items' => $items, 'align' => $align, 'valign' => $valign, 'width' => $width];
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function text(string $text, array $style = []): array
    {
        return [
            'type' => 'text',
            'text' => $text,
            'language' => self::oneOf($style['language'] ?? null, self::LANGUAGES, 'both'),
            'font_family' => self::oneOf($style['font_family'] ?? null, ['', ...array_keys(self::FONTS)], ''),
            'font_size' => self::number($style['font_size'] ?? null, 6, 28, 10),
            'bold' => (bool) ($style['bold'] ?? false),
            'underline' => (bool) ($style['underline'] ?? false),
            'color' => self::color($style['color'] ?? null, '#111111'),
        ];
    }

    /** @return array<string, mixed> */
    private static function image(string $key, float $widthMm, bool $full = false): array
    {
        // "full": the picture prints across the whole width between the margins (a header or footer drawn as one image).
        return ['type' => 'image', 'image' => $key, 'width_mm' => $widthMm, 'full' => $full, 'language' => 'both'];
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function number(mixed $value, float $min, float $max, float $default): float
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return round(max($min, min($max, (float) $value)), 1);
    }

    private static function color(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtoupper($value) : $default;
    }
}
