<?php

declare(strict_types=1);

namespace App\Enums\Discipline;

/** Art. 19(1) preliminary objections. */
enum DisciplinaryObjectionGround: string
{
    case StatuteBarred = 'statute_barred';
    case NotDisciplinary = 'not_disciplinary';
    case AlreadyDecided = 'already_decided';
}
