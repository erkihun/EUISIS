<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\OrganizationStructureImport\ImportIssue;
use RuntimeException;

/**
 * Thrown when an uploaded Organization Structure file cannot be opened as a
 * spreadsheet at all (corrupt, wrong format, unreadable).
 *
 * This is distinct from a workbook that *reads* but fails validation — those
 * problems are reported as {@see ImportIssue}
 * rows in the preview, not as an exception.
 */
class InvalidStructureWorkbookException extends RuntimeException {}
