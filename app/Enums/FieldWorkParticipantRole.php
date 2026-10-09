<?php

declare(strict_types=1);

namespace App\Enums;

enum FieldWorkParticipantRole: string
{
    /** The requester. Always present, exactly once. */
    case Lead = 'lead';
    case Member = 'member';
}
