<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/** Timeliness of the FIRST valid submission against a deadline, at an as-of time. */
enum Timeliness: string
{
    /** The deadline has not passed and nothing was submitted. */
    case NotDue = 'not_due';
    /** Submitted before a deadline that is still in the future. */
    case Early = 'early';
    /** The deadline has passed; first submission at or before it. */
    case OnTime = 'on_time';
    /** First submission after the deadline. */
    case Late = 'late';
    /** The deadline has passed with no valid submission. */
    case Overdue = 'overdue';
}
