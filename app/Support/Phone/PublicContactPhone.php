<?php

declare(strict_types=1);

namespace App\Support\Phone;

/**
 * An institution's public contact number, validated before it can become a
 * `tel:` link.
 *
 * Accepted, and nothing else:
 *  - an Ethiopian number as +251…, 251… or 0… with a nine-digit national
 *    number, stored as +251XXXXXXXXX;
 *  - another country's number written with a leading + (8–15 digits);
 *  - a 3–5 digit short code (a public-service hotline), kept as entered.
 *
 * Only digits, spaces, dots, hyphens, brackets and one leading + may appear,
 * so letters, `:`, `;`, `,`, `#`, `*` and extensions are refused and no
 * scheme such as `javascript:` can reach the link. Ambiguous input (a
 * nine-digit number without its leading 0, say) is refused rather than
 * guessed at.
 */
final readonly class PublicContactPhone
{
    private const MAX_LENGTH = 32;

    private function __construct(
        /** What the dialler receives: +251XXXXXXXXX, +<country number> or a short code. */
        public string $dial,
        /** What a person reads: 011 123 4567, 0911 223 344, +44…, 8044. */
        public string $display,
    ) {}

    public static function parse(?string $value): ?self
    {
        $value = trim((string) $value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || ! preg_match('/^\+?[0-9 ().\-]+$/D', $value)) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $value);
        $international = str_starts_with($value, '+');

        if ($international) {
            if (str_starts_with($digits, '251')) {
                return self::ethiopian(substr($digits, 3));
            }

            return strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0'
                ? new self('+'.$digits, '+'.$digits)
                : null;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '251')) {
            return self::ethiopian(substr($digits, 3));
        }

        if (strlen($digits) === 10 && $digits[0] === '0') {
            return self::ethiopian(substr($digits, 1));
        }

        if (strlen($digits) >= 3 && strlen($digits) <= 5 && $digits[0] !== '0' && $digits === $value) {
            return new self($digits, $digits);
        }

        return null;
    }

    public function telUri(): string
    {
        return 'tel:'.$this->dial;
    }

    /** @return array{href: string, display: string} */
    public function toPublicArray(): array
    {
        return ['href' => $this->telUri(), 'display' => $this->display];
    }

    private static function ethiopian(string $national): ?self
    {
        if (strlen($national) !== 9 || $national[0] === '0') {
            return null;
        }

        // Mobile (9…, 7…) reads 0911 223 344; a fixed line reads 011 123 4567.
        $display = in_array($national[0], ['7', '9'], true)
            ? '0'.substr($national, 0, 3).' '.substr($national, 3, 3).' '.substr($national, 6, 3)
            : '0'.substr($national, 0, 2).' '.substr($national, 2, 3).' '.substr($national, 5, 4);

        return new self('+251'.$national, $display);
    }
}
