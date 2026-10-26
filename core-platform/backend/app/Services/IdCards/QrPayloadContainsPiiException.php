<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use RuntimeException;

/**
 * The QR payload carries something other than a bare verification URL.
 *
 * Raised before rendering, because a QR printed on plastic cannot be recalled:
 * anything encoded into it is disclosed permanently to everyone who scans the
 * card. The message deliberately names the offending shape, never the value.
 */
final class QrPayloadContainsPiiException extends RuntimeException
{
    /** Machine-readable marker for API and log consumers. */
    public const CODE = 'QR_PAYLOAD_CONTAINS_PII';

    public static function structuredData(): self
    {
        return new self(
            self::CODE.': the QR payload looks like a JSON object. '
            .'The QR must contain only the public verification URL.',
        );
    }

    public static function forbiddenCharacter(string $char): self
    {
        return new self(sprintf(
            '%s: the QR payload contains "%s", which would allow employee data '
            .'to be passed as a query parameter. The QR must contain only the '
            .'public verification URL.',
            self::CODE,
            $char,
        ));
    }

    public static function forbiddenField(string $field): self
    {
        return new self(sprintf(
            '%s: the QR payload mentions "%s". The QR must contain only the '
            .'public verification URL and the card UUID.',
            self::CODE,
            $field,
        ));
    }

    public static function employeeValue(): self
    {
        return new self(
            self::CODE.': the QR payload contains a value belonging to the card '
            .'holder. The QR must contain only the public verification URL and '
            .'the card UUID.',
        );
    }
}
