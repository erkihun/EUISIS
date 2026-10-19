<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI;

/**
 * Validates a provider's JSON against the prompt's schema (brief §118):
 * required fields, enums, lengths, list sizes, scalar types. Strings are
 * stripped of tags and control characters. Anything that does not fit is
 * rejected as a whole — a half-valid diagnosis is not rendered.
 */
final class AiResponseValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array{ok: bool, data: array<string, mixed>|null, error: ?string}
     */
    public static function validate(mixed $decoded, array $schema): array
    {
        if (! is_array($decoded)) {
            return ['ok' => false, 'data' => null, 'error' => 'not_an_object'];
        }
        $out = [];
        foreach ($schema as $field => $rule) {
            if (! array_key_exists($field, $decoded)) {
                return ['ok' => false, 'data' => null, 'error' => "missing:{$field}"];
            }
            $value = self::field($decoded[$field], $rule);
            if ($value === null) {
                return ['ok' => false, 'data' => null, 'error' => "invalid:{$field}"];
            }
            $out[$field] = $value;
        }

        return ['ok' => true, 'data' => $out, 'error' => null];
    }

    /** @param  array<string, mixed>  $rule */
    private static function field(mixed $value, array $rule): mixed
    {
        $type = $rule['type'] ?? 'string';
        switch ($type) {
            case 'string':
                if (! is_scalar($value)) {
                    return null;
                }
                $clean = self::cleanText((string) $value);
                if (isset($rule['max']) && mb_strlen($clean) > $rule['max']) {
                    $clean = mb_substr($clean, 0, (int) $rule['max']);
                }

                return $clean;
            case 'enum':
                return is_string($value) && in_array($value, $rule['values'], true) ? $value : null;
            case 'bool':
                return is_bool($value) ? $value : (in_array($value, ['true', 'false', 1, 0, '1', '0'], true) ? in_array($value, ['true', 1, '1'], true) : null);
            case 'int':
                return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
            case 'list':
                if (! is_array($value)) {
                    return null;
                }
                $items = [];
                foreach (array_values($value) as $i => $item) {
                    if ($i >= ($rule['max_items'] ?? 10)) {
                        break;
                    }
                    $itemRule = $rule['item'] ?? ['type' => 'string'];
                    if (isset($itemRule['type'])) {
                        $clean = self::field($item, $itemRule);
                        if ($clean === null) {
                            return null;
                        }
                        $items[] = $clean;
                    } else {
                        if (! is_array($item)) {
                            return null;
                        }
                        $object = [];
                        foreach ($itemRule as $k => $r) {
                            if (! array_key_exists($k, $item)) {
                                return null;
                            }
                            $clean = self::field($item[$k], $r);
                            if ($clean === null) {
                                return null;
                            }
                            $object[$k] = $clean;
                        }
                        $items[] = $object;
                    }
                }

                return $items;
        }

        return null;
    }

    /** Plain text only: tags, scripts and control characters are removed; the result is rendered as text anyway. */
    public static function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim($text);
    }

    /** Extracts the first JSON object from a model answer that may be wrapped in prose or a code fence. */
    public static function extractJson(string $text): mixed
    {
        $trimmed = trim($text);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
