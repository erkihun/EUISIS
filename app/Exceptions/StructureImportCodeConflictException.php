<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a code generated during confirm differs from the one the preview
 * showed the user — i.e. another import consumed the same sequence in between.
 *
 * Rolling back and reporting a conflict is the safe outcome: the alternative is
 * silently importing under codes nobody approved.
 */
class StructureImportCodeConflictException extends RuntimeException {}
