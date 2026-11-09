<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use RuntimeException;

/** Why an employee cannot be matched to, or signed into, a court account (an error_code for EUISIS). */
final class EmployeeCourtAccessException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
