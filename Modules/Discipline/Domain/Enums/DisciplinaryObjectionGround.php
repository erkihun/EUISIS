<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Enums;

/** Art. 19(1) preliminary objections. */
enum DisciplinaryObjectionGround: string
{
    case StatuteBarred = 'statute_barred';
    case NotDisciplinary = 'not_disciplinary';
    case AlreadyDecided = 'already_decided';
}
