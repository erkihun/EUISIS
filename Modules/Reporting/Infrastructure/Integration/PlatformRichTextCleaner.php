<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Integration;

use App\Support\RichText\RichTextSanitizer;
use Modules\Reporting\Application\Ports\RichTextCleaner;

/** The application's one rich-text allowlist (config/richtext.php): headings, lists, tables, basic emphasis; no scripts, no remote content. */
final readonly class PlatformRichTextCleaner implements RichTextCleaner
{
    public function __construct(private RichTextSanitizer $sanitizer) {}

    public function clean(?string $html): string
    {
        return $this->sanitizer->sanitize($html) ?? '';
    }
}
