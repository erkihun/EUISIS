<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaExclusionType: string
{
    case Leave           = 'leave';
    case SickLeave       = 'sick_leave';
    case MaternityLeave  = 'maternity_leave';
    case Suspension      = 'suspension';
    case Training        = 'training';
    case FieldAssignment = 'field_assignment';
    case UnpaidLeave     = 'unpaid_leave';
    case Other           = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Leave           => __('cafeteria.exclusionTypeLeave'),
            self::SickLeave       => __('cafeteria.exclusionTypeSickLeave'),
            self::MaternityLeave  => __('cafeteria.exclusionTypeMaternityLeave'),
            self::Suspension      => __('cafeteria.exclusionTypeSuspension'),
            self::Training        => __('cafeteria.exclusionTypeTraining'),
            self::FieldAssignment => __('cafeteria.exclusionTypeFieldAssignment'),
            self::UnpaidLeave     => __('cafeteria.exclusionTypeUnpaidLeave'),
            self::Other           => __('cafeteria.exclusionTypeOther'),
        };
    }
}
