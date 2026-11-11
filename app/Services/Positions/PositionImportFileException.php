<?php

declare(strict_types=1);

namespace App\Services\Positions;

use RuntimeException;

/** The uploaded file as a whole cannot be read; the message is shown beside the file field. */
class PositionImportFileException extends RuntimeException {}
