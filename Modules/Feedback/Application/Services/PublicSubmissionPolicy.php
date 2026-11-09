<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\PublicSubmissionTypes;

/**
 * The identity and field matrix for public submissions, as each type's own
 * module defines it (ADR 0032; PublicSubmissionType::requirements):
 *
 * | Type                 | Full name | Phone    | Anonymous | Tip type | Service received   |
 * |----------------------|-----------|----------|-----------|----------|--------------------|
 * | Feedback             | optional  | optional | allowed   | —        | required (employee)|
 * | Tip-off (suggestion) | optional  | optional | allowed   | required | not required       |
 * | Complaint            | required  | required | no        | —        | optional           |
 *
 * Validation (ValidatesPublicSubmitter), storage (PublicFeedbackSubmissionService)
 * and the public page all read this class; none of them restates a rule.
 * Allow-attachments in the city policy (PublicFeedbackSettings) can still turn
 * evidence off; it cannot turn it on for a type that has none.
 */
final class PublicSubmissionPolicy
{
    public function __construct(private readonly PublicSubmissionTypes $types) {}

    public function for(ServiceFeedbackType $type, bool $attachmentsEnabled = true): SubmissionRequirements
    {
        return new SubmissionRequirements($type, ...$this->types->for($type)->requirements($attachmentsEnabled));
    }
}
