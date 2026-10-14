<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Illuminate\Support\Arr;

/**
 * The Situation Room's words, in one place for both the pages and the
 * exports: lang/{en,am}/situation_room.php. Pages receive the flattened
 * dictionary for both languages (the language switch is client-side), and
 * exports resolve the same keys on the server, so a PDF can never say
 * something the screen does not.
 */
final class SituationLabels
{
    public const PREFIX = 'situationRoom.';

    /** @return array{en: array<string, string>, am: array<string, string>} */
    public static function dictionary(): array
    {
        return [
            'en' => self::flat('en'),
            'am' => self::flat('am'),
        ];
    }

    /**
     * Resolve a label payload ({key}, {en, am} or a plain string) in a locale.
     *
     * @param  array<string, mixed>|string|int|float|null  $label
     */
    public static function resolve(array|string|int|float|null $label, string $locale): string
    {
        if ($label === null) {
            return '';
        }
        if (! is_array($label)) {
            return (string) $label;
        }
        if (isset($label['key'])) {
            return self::text((string) $label['key'], $locale);
        }

        $order = $locale === 'am' ? ['am', 'en'] : ['en', 'am'];
        foreach ($order as $language) {
            if (trim((string) ($label[$language] ?? '')) !== '') {
                return (string) $label[$language];
            }
        }

        return '';
    }

    /** One key (with or without the situationRoom. prefix) in a locale; the key itself when missing. */
    public static function text(string $key, string $locale): string
    {
        $short = str_starts_with($key, self::PREFIX) ? substr($key, strlen(self::PREFIX)) : $key;
        $value = trans('situation_room.'.$short, [], $locale);

        return is_string($value) && $value !== 'situation_room.'.$short ? $value : $short;
    }

    /** @return array<string, string> */
    private static function flat(string $locale): array
    {
        $lines = trans('situation_room', [], $locale);

        return is_array($lines) ? Arr::dot($lines) : [];
    }
}
