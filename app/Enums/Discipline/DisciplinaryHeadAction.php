<?php

declare(strict_types=1);

namespace App\Enums\Discipline;

/**
 * Art. 28(1): the head approves, modifies or overturns the recommendation, or
 * orders re-examination. Art. 13(5): on an expedited inquiry whose outcome
 * calls for a serious penalty, the head refers the charge to the disciplinary
 * committee instead of deciding it.
 */
enum DisciplinaryHeadAction: string
{
    case Approve = 'approve';
    case Modify = 'modify';
    case Overturn = 'overturn';
    case Reinvestigate = 'reinvestigate';
    case ReferToCommittee = 'refer_to_committee';
}
