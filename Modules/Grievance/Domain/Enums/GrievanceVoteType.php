<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Committee vote on a decision draft (only when voting is enabled in settings).
 */
enum GrievanceVoteType: string
{
    case Concur = 'concur';
    case Dissent = 'dissent';
    case Abstain = 'abstain';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
