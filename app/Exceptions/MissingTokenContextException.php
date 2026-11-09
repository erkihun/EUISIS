<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a format token requires a context key that was not supplied.
 *
 * Example: {ORG_CODE} requires 'organization_id' in the context array.
 */
class MissingTokenContextException extends RuntimeException {}
