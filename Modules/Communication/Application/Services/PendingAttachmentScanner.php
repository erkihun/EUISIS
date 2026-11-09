<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use Modules\Communication\Domain\Enums\AttachmentScanStatus;

/** Default scanner: no engine configured, so a file waits (PENDING) rather than being trusted. */
final class PendingAttachmentScanner implements AttachmentScanner
{
    public function scan(string $disk, string $path): AttachmentScanStatus
    {
        return AttachmentScanStatus::Pending;
    }
}
