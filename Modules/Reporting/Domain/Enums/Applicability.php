<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/** Whether an obligation counts in the denominators. A Nil Report is still `Required`. */
enum Applicability: string
{
    case Required = 'required';
    case Exempted = 'exempted';
    case Cancelled = 'cancelled';
}
