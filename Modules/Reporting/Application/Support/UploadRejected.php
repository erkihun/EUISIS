<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use RuntimeException;

/**
 * An uploaded file was refused. `reason` is a stable code: not_docx,
 * macros_not_allowed, too_large, archive_too_large, unsafe_content,
 * external_links, type_not_allowed, malware_detected, unreadable.
 */
final class UploadRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("reporting_upload_rejected:{$reason}");
    }
}
