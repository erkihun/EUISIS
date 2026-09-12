<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Models\IdCard;

/**
 * Guards what may be encoded into a card's QR code.
 *
 * The QR is printed on plastic and readable by anyone holding the card, so it
 * carries a public verification URL and nothing else. Any employee data in the
 * payload would be permanently disclosed to every scanner, with no consent and
 * no way to revoke it short of reprinting the card.
 *
 * This validator is the last gate before rendering: it rejects a payload that
 * is not a bare verification URL, rather than trying to sanitise one.
 */
final class QrPayloadSecurityValidator
{
    /**
     * Query strings and fragments are refused outright — they are where
     * employee data would be smuggled in as parameters.
     */
    private const FORBIDDEN_CHARS = ['?', '#', '&', '=', ';', ' '];

    /**
     * Field names that must never appear in a payload, whatever their value.
     * Matched case-insensitively against the raw payload.
     */
    private const FORBIDDEN_KEYS = [
        'employee_id', 'employee_number', 'employeeid', 'employeenumber',
        'national_id', 'nationalid', 'fin', 'fan',
        'name', 'full_name', 'fullname', 'first_name', 'last_name',
        'phone', 'mobile', 'tel', 'email', 'mail',
        'organization', 'organisation', 'org', 'bureau', 'department',
        'position', 'job_title', 'jobtitle', 'grade', 'salary',
        'service', 'entitlement', 'cafeteria', 'transport',
        'dob', 'date_of_birth', 'gender', 'address',
    ];

    /**
     * Reject a payload that is anything other than a public verification URL.
     *
     * @throws QrPayloadContainsPiiException
     */
    public function assertSafe(string $payload): void
    {
        $trimmed = trim($payload);

        // A JSON object is the classic way employee data ends up in a QR.
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            throw QrPayloadContainsPiiException::structuredData();
        }

        foreach (self::FORBIDDEN_CHARS as $char) {
            if (str_contains($trimmed, $char)) {
                throw QrPayloadContainsPiiException::forbiddenCharacter($char);
            }
        }

        $lower = mb_strtolower($trimmed);

        foreach (self::FORBIDDEN_KEYS as $key) {
            if (str_contains($lower, $key)) {
                throw QrPayloadContainsPiiException::forbiddenField($key);
            }
        }
    }

    /**
     * Check a payload against one card's own data, catching a value that is
     * PII without naming a field — a bare phone number or employee number
     * appended to the URL, for instance.
     *
     * @throws QrPayloadContainsPiiException
     */
    public function assertCarriesNoCardData(string $payload, IdCard $card): void
    {
        $this->assertSafe($payload);

        $employee = $card->employee;

        $sensitive = array_filter([
            $employee?->employee_number,
            $employee?->full_name,
            $employee?->name_en,
            $employee?->phone,
            $employee?->email,
            $employee?->national_id,
            // The card number identifies the plastic and is printed in the
            // clear, but it still has no place in a scannable payload.
            $card->card_number,
        ], static fn ($value): bool => is_string($value) && mb_strlen(trim($value)) >= 3);

        $lower = mb_strtolower($payload);

        foreach ($sensitive as $value) {
            if (str_contains($lower, mb_strtolower(trim((string) $value)))) {
                throw QrPayloadContainsPiiException::employeeValue();
            }
        }
    }
}
