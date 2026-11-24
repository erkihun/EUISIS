<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/** What the reporting side may do once the effective deadline has passed. */
enum LatePolicy: string
{
    /** Late submissions are accepted and labelled late. */
    case Accept = 'accept';
    /** Accepted only with a stated reason for the delay. */
    case AcceptWithReason = 'accept_with_reason';
    /** Refused until an extension is granted. */
    case Block = 'block';
}
