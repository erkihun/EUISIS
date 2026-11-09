<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Every number the generator tried was already taken — typically after a
 * sequence was reset onto a range of codes still in use.
 */
final class SequenceCodesExhaustedException extends RuntimeException {}
