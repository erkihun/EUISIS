<?php

declare(strict_types=1);

namespace App\Services\Employees;

use RuntimeException;

/**
 * The uploaded file as a whole cannot be read as an employee list.
 *
 * Distinct from a row problem: nothing in the file can be checked, so no batch
 * is recorded and the message is shown beside the file field. The message is
 * already translated and safe to show.
 */
class EmployeeImportFileException extends RuntimeException {}
