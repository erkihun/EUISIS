<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\Extensions\PublicSubmissionType;

/**
 * The registry of public submission types (ADR 0032). Each type module
 * registers its PublicSubmissionType from its provider.
 */
interface PublicSubmissionTypes
{
    public function register(PublicSubmissionType $type): void;

    public function has(ServiceFeedbackType|string $type): bool;

    /** @throws \LogicException when the type's module is not installed */
    public function for(ServiceFeedbackType|string $type): PublicSubmissionType;

    /** @return list<PublicSubmissionType> */
    public function all(): array;
}
