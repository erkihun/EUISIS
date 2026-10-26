<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A card template's layout (ADR 0010): a bounded set of settings, never
 * markup or script. The renderer draws from these values only, escaping
 * every text, so a template cannot inject anything into a card.
 */
final class CardTemplateConfig
{
    public const ORIENTATIONS = ['landscape', 'portrait'];

    /** What a card can show. The QR, the employee number and the card number are always shown. */
    public const FIELDS = ['full_name', 'position', 'organization', 'employee_number', 'card_number', 'issued_at', 'expires_at', 'photo', 'signature', 'qr'];

    public const REQUIRED_FIELDS = ['employee_number', 'card_number', 'qr'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'orientation' => 'landscape',
            'colors' => ['primary' => '#1F4E79', 'accent' => '#C9A227', 'text' => '#111827', 'background' => '#FFFFFF'],
            // Blank headers show the city name from Core settings › ID cards.
            'header_en' => '',
            'header_am' => '',
            'footer_en' => '',
            'footer_am' => '',
            'fields' => ['full_name', 'position', 'organization', 'employee_number', 'card_number', 'expires_at', 'photo', 'qr'],
        ];
    }

    /**
     * The validated, normalized layout; anything unknown is dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validate(array $input, bool $photoRequired = true): array
    {
        $text = ['nullable', 'string', 'max:120', 'not_regex:/[<>\x00-\x1F]/u'];
        $color = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $data = Validator::make($input, [
            'orientation' => ['required', Rule::in(self::ORIENTATIONS)],
            'colors' => ['required', 'array'],
            'colors.primary' => $color, 'colors.accent' => $color, 'colors.text' => $color, 'colors.background' => $color,
            'header_en' => $text, 'header_am' => $text, 'footer_en' => $text, 'footer_am' => $text,
            'fields' => ['required', 'array', 'max:'.count(self::FIELDS)],
            'fields.*' => ['string', Rule::in(self::FIELDS)],
        ])->after(function ($validator) use ($input, $photoRequired): void {
            $fields = (array) ($input['fields'] ?? []);
            foreach (self::REQUIRED_FIELDS as $field) {
                if (! in_array($field, $fields, true)) {
                    $validator->errors()->add('fields', "A card must show the {$field}.");
                }
            }
            if ($photoRequired && ! in_array('photo', $fields, true)) {
                $validator->errors()->add('fields', 'Cards require a photo (ID card settings), so the template must show it.');
            }
        })->validate();

        return [
            'orientation' => $data['orientation'],
            'colors' => array_map('strtoupper', array_intersect_key($data['colors'], array_flip(['primary', 'accent', 'text', 'background']))),
            'header_en' => (string) ($data['header_en'] ?? ''),
            'header_am' => (string) ($data['header_am'] ?? ''),
            'footer_en' => (string) ($data['footer_en'] ?? ''),
            'footer_am' => (string) ($data['footer_am'] ?? ''),
            'fields' => array_values(array_intersect(self::FIELDS, $data['fields'])),
        ];
    }
}
