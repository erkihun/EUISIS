<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Intelligence\IntelligenceLabels;

/**
 * Turns the label keys the Situation Room / Intelligence payloads carry
 * (`['key' => 'intelligence.kpi.x']`, `['en' => …, 'am' => …]` or a bare
 * key) into text for one locale, so the assistant can speak figures the
 * same way the pages show them.
 */
final class AssistantLabels
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $dictionary = null;

    /** @param  array<string, mixed>|string|null  $label  @param  array<string, mixed>  $params */
    public static function text(array|string|null $label, array $params = [], string $locale = 'en'): string
    {
        if ($label === null) {
            return '';
        }
        if (is_array($label)) {
            if (isset($label['en']) || isset($label['am'])) {
                return (string) ($label[$locale] ?? $label['en'] ?? $label['am'] ?? '');
            }
            $params = is_array($label['params'] ?? null) ? $label['params'] + $params : $params;
            $label = (string) ($label['key'] ?? '');
        }
        $dictionary = self::dictionary()[$locale] ?? self::dictionary()['en'] ?? [];
        $text = $dictionary[$label] ?? $dictionary['intelligence.'.$label] ?? null;
        if ($text === null) {
            $text = __($label, [], $locale);
            if (! is_string($text) || $text === $label) {
                $text = (string) str_replace(['_', '.'], ' ', (string) preg_replace('/^.*\./', '', $label));
            }
        }
        foreach ($params as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $text = str_replace(':'.$name, (string) $value, $text);
            }
        }

        return $text;
    }

    /** @return array<string, array<string, string>> */
    private static function dictionary(): array
    {
        return self::$dictionary ??= IntelligenceLabels::dictionary();
    }

    public static function reset(): void
    {
        self::$dictionary = null;
    }
}
