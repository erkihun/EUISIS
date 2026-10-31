<?php

declare(strict_types=1);

namespace Modules\Court\Http\Support\Ui;

use Closure;

/**
 * Links typed into the landing-page manager (slide and banner buttons, social
 * links, resource URLs) end up in an `href` on the public site, so only three
 * shapes are accepted: an http(s) address, an in-page `#anchor` and a path on
 * this site (`/court/applicant/login`). Anything else, such as `javascript:` or
 * `data:` and the protocol-relative `//host`, is refused when saved
 * (rule()) and dropped when shown (safe()), so a link stored before this
 * check can never reach the page either.
 */
final class EditorLink
{
    /** The link as given when it is one of the accepted shapes, otherwise null. */
    public static function safe(mixed $url, bool $allowRelative = true): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        // Browsers drop tabs and newlines inside a URL and read `\` as `/`, which
        // would turn "java\nscript:" or "/\evil.test" into something else.
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return null;
        }

        if ($allowRelative && (str_starts_with($url, '#') || (str_starts_with($url, '/') && ! str_starts_with($url, '//')))) {
            return $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) && is_string($host) && $host !== ''
            ? $url
            : null;
    }

    /** A validation rule refusing any link safe() would drop. */
    public static function rule(bool $allowRelative = true): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowRelative): void {
            if ($value === null || $value === '') {
                return;
            }
            if (self::safe($value, $allowRelative) === null) {
                $fail($allowRelative ? 'court::admin_landing.validation.safe_link' : 'court::admin_landing.validation.safe_web_link')->translate();
            }
        };
    }
}
