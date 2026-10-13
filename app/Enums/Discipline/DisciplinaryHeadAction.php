<?php

declare(strict_types=1);

namespace App\Enums\Discipline;

/** Art. 28(1): the head approves, modifies or overturns the recommendation, or orders re-examination. */
enum DisciplinaryHeadAction: string
{
    case Approve = 'approve';
    case Modify = 'modify';
    case Overturn = 'overturn';
    case Reinvestigate = 'reinvestigate';
}
