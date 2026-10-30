<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Enums;

/** Art. 20(2)(b): the accused admits or denies each alleged act. */
enum DisciplinaryPlea: string
{
    case Admitted = 'admitted';
    case Denied = 'denied';
}
