<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

use Modules\Reporting\Application\Data\ReportDocument;

/** Turns a report into an editable Word file. Implementations fetch nothing from outside and sign nothing. */
interface DocumentRenderer
{
    /** Writes a .docx to $targetPath (a local file path). */
    public function renderWord(ReportDocument $document, string $targetPath): void;
}
