<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

use Modules\Reporting\Application\Support\UploadRejected;

/**
 * Checks an uploaded file before it is kept: a real .docx (not a renamed or
 * macro-enabled file, not an archive bomb, no embedded objects or external
 * links), or an allowed annex type; then a malware scan when one is configured.
 */
interface UploadInspector
{
    /**
     * @param  string  $kind  report_document, annex or evidence
     * @return string scan status: clean, or not_scanned when no scanner is configured
     *
     * @throws UploadRejected with a stable reason code
     */
    public function inspect(string $path, string $originalName, string $kind): string;
}
