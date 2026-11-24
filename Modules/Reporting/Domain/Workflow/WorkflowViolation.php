<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Workflow;

use DomainException;

/** A rule of the reporting workflow refused the action. `reason` is a stable code the presentation layer translates. */
final class WorkflowViolation extends DomainException
{
    /** @param array<string, string|int> $context */
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct($reason);
    }
}
