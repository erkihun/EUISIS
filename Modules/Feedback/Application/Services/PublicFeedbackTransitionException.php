<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use DomainException;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;

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
