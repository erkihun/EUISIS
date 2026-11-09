<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use RuntimeException;

/** A call to the EUISIS API that did not succeed; carries EUISIS's error code and request id when it sent them. */
final class EuisisException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
