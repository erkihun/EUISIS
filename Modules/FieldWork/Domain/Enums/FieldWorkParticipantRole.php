<?php

declare(strict_types=1);

namespace Modules\FieldWork\Domain\Enums;

enum FieldWorkParticipantRole: string
{
    /** The requester. Always present, exactly once. */
    case Lead = 'lead';
    case Member = 'member';
}
