<?php

declare(strict_types=1);

namespace App\Enums;

enum FieldWorkLocationValidationStatus: string
{
    case WithinExpectedArea = 'within_expected_area';
    case OutsideExpectedArea = 'outside_expected_area';
    case CannotValidate = 'cannot_validate';
}
