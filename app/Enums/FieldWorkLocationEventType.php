<?php

declare(strict_types=1);

namespace App\Enums;

enum FieldWorkLocationEventType: string
{
    case RequestSubmission = 'request_submission';
    case Departure = 'departure';
    case FieldCheckIn = 'field_check_in';
    case FieldCheckOut = 'field_check_out';
    case Return = 'return';
    case Completion = 'completion';
}
