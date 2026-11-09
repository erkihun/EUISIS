<?php

namespace App\Services\Backup\Infrastructure;

use RuntimeException;

/** The tool answered, but its output failed validation. Carries a parser result code only. */
final class InvalidCommandOutput extends RuntimeException
{
    public function __construct(public readonly string $parserResult)
    {
        parent::__construct('INVALID_COMMAND_OUTPUT');
    }
}
