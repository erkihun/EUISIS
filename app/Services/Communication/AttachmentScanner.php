<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\AttachmentScanStatus;

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
