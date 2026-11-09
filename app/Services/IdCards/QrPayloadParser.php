<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use Illuminate\Support\Str;

/**
 * Extracts the stable public card UUID from whatever a scanner hands over.
 *
 * One printed QR has to work at every terminal — the cafeteria scanner, a
 * provider or transport terminal, a phone camera, or an operator pasting a
 * value by hand. Each of those delivers the payload slightly differently, so
 * the parsing lives here rather than being re-implemented per terminal.
 *
 * Accepted forms:
 *   - the printed payload:      https://domain/id-checker/{uuid}
 *   - a configured short URL:   https://short-domain/c/{uuid}
 *   - the legacy public page:   https://domain/verify/card/{uuid}
 *   - a bare UUID typed or pasted by an operator
 *
 * Anything else returns null. The parser never trusts the host, only the path
 * shape and the UUID itself, so a lookalike domain cannot smuggle a different
 * identifier through.
 */
final class QrPayloadParser
{
    /**
     * Path segments that may precede the UUID. `/c/` is the short form the
     * card can be configured to print; the others are the full and legacy
     * public verification pages.
     */
    private const PATH_SEGMENTS = ['id-checker', 'c', 'verify/card'];

    /** The canonical UUID shape, used for both matching and validation. */
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * The public card UUID a scan refers to, or null when the value is not a
     * recognisable card reference.
     */
    public function parse(?string $scanValue): ?string
    {
        $value = trim((string) $scanValue);

        if ($value === '') {
            return null;
        }

        // A bare UUID: an operator typing or pasting the reference by hand.
        if (preg_match('/^'.self::UUID.'$/D', $value) === 1) {
            return $this->normalise($value);
        }

        // A URL. The UUID must be the last path segment, directly after one of
        // the known prefixes, so a stray UUID elsewhere in a query string or
        // fragment is not mistaken for the card reference.
        foreach (self::PATH_SEGMENTS as $segment) {
            // Delimited with ~ rather than #, because the trailing character
            // class has to contain a literal # for fragment URLs.
            $pattern = '~/'.preg_quote($segment, '~').'/('.self::UUID.')(?:[/?#]|$)~';

            if (preg_match($pattern, $value, $matches) === 1) {
                return $this->normalise($matches[1]);
            }
        }

        return null;
    }

    /** Whether a scanned value refers to a card at all. */
    public function looksLikeCardReference(?string $scanValue): bool
    {
        return $this->parse($scanValue) !== null;
    }

    /**
     * Lower-cased, so a scanner that reports hex in upper case still matches
     * the stored value. Validated once more before returning, because the
     * result goes straight into a database lookup.
     */
    private function normalise(string $uuid): ?string
    {
        $normalised = strtolower($uuid);

        return Str::isUuid($normalised) ? $normalised : null;
    }
}
