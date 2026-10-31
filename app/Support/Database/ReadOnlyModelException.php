<?php

declare(strict_types=1);

namespace App\Support\Database;

use LogicException;

final class ReadOnlyModelException extends LogicException
{
    public static function for(string $model, string $operation): self
    {
        return new self("{$model} is a read model: {$operation} is refused. Write through the owning module's contracts.");
    }
}
