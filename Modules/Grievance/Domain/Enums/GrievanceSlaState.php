<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Computed SLA state shown in the UI (never stored).
 */
enum GrievanceSlaState: string
{
    case OnTrack = 'on_track';
    case DueSoon = 'due_soon';
    case DueToday = 'due_today';
    case Overdue = 'overdue';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case NoDeadline = 'no_deadline';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
