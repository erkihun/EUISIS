<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use LogicException;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\Extensions\PublicSubmissionType;
use Modules\Feedback\Contracts\PublicSubmissionTypes;

/** The registered public submission types (a container singleton). */
final class PublicSubmissionTypeRegistry implements PublicSubmissionTypes
{
    /** @var array<string, PublicSubmissionType> */
    private array $types = [];

    public function register(PublicSubmissionType $type): void
    {
        $this->types[$type->type()->value] = $type;
    }

    public function has(ServiceFeedbackType|string $type): bool
    {
        return isset($this->types[$type instanceof ServiceFeedbackType ? $type->value : $type]);
    }

    public function for(ServiceFeedbackType|string $type): PublicSubmissionType
    {
        $key = $type instanceof ServiceFeedbackType ? $type->value : $type;

        return $this->types[$key] ?? throw new LogicException("No module registers the public submission type [{$key}].");
    }

    public function all(): array
    {
        return array_values($this->types);
    }
}
