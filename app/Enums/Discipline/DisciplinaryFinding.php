<?php

declare(strict_types=1);

namespace App\Enums\Discipline;

/** Art. 26: responsibility established, or not established (acquittal). */
enum DisciplinaryFinding: string
{
    case Responsible = 'responsible';
    case NotEstablished = 'not_established';
}
