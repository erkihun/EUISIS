<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The name a public upload arrived with (brief §65, 67): no path parts, no
 * control characters, and no executable or script extension anywhere in it
 * — `evidence.php.pdf` is refused as firmly as `evidence.php`. Content type
 * and size are checked by the ordinary rules beside this one; the stored
 * file is renamed regardless.
 */
final class SafeUploadName implements ValidationRule
{
    private const DANGEROUS = '/\.(php\d?|phtml|phar|exe|bat|cmd|com|sh|bash|ps1|vbs|vbe|js|jse|jar|msi|scr|dll|cgi|pl|py|rb|htaccess|svg|html?)(\.|$)/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = $value instanceof UploadedFile ? (string) $value->getClientOriginalName() : (string) $value;

        if ($name === ''
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || preg_match(self::DANGEROUS, $name) === 1) {
            $fail('service-feedback.validation.attachment_type')->translate();
        }
    }
}
