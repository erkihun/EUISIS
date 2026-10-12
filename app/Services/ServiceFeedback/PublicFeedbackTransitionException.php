<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use DomainException;

/** The requested status is not reachable from the current one for this submission type. */
final class PublicFeedbackTransitionException extends DomainException
{
    public function __construct(
        public readonly ServiceFeedbackType $type,
        public readonly ServiceFeedbackStatus $from,
        public readonly ServiceFeedbackStatus $to,
    ) {
        parent::__construct(sprintf('A %s cannot move from %s to %s.', $type->value, $from->value, $to->value));
    }
}
