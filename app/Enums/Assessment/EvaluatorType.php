<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Who evaluates. A form version configures only the types it needs.
 */
enum EvaluatorType: string
{
    case Self = 'self';
    case DirectManager = 'direct_manager';
    case Peer = 'peer';
    case Subordinate = 'subordinate';
    case Committee = 'committee';
    case ConfiguredEmployee = 'configured_employee';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
