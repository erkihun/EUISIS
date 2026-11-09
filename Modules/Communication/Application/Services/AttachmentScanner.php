<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use Modules\Communication\Domain\Enums\AttachmentScanStatus;

/**
 * Malware scanning hook. Bind a real implementation (ClamAV, a cloud scanner)
 * in a service provider; the default scans nothing and, when scanning is
 * switched on in settings, leaves files PENDING so they are not downloadable
 * until a real scanner clears them.
 */
interface AttachmentScanner
{
    /** @param string $disk @param string $path */
    public function scan(string $disk, string $path): AttachmentScanStatus;
}
