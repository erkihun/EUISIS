<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a sequence scope token cannot be resolved from the provided context.
 *
 * Example: a format {ORG_TYPE_PREFIX}-{SEQUENCE} with strategy=auto requires
 * the context to supply enough data to resolve ORG_TYPE_PREFIX.
 */
class MissingSequenceScopeContextException extends RuntimeException {}
