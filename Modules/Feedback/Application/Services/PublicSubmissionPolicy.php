<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use Modules\Feedback\Contracts\Data\ServiceFeedbackType;

/**
 * The one identity and field matrix for public submissions.
 *
 * | Type                 | Full name | Phone    | Anonymous | Tip type | Service received   |
 * |----------------------|-----------|----------|-----------|----------|--------------------|
 * | Feedback             | required  | required | no        | —        | required (employee)|
 * | Tip-off (suggestion) | optional  | optional | allowed   | required | not required       |
 * | Complaint            | required  | required | no        | —        | optional           |
 *
 * Feedback and Complaint are answered to a named person, so they are never
 * anonymous. A Tip-off follows the paper Tip-off intake form: the tipster's
 * name, phone, e-mail and address are voluntary, and a person may report what
 * they observed without having received a service at all.
 *
 * Validation (ValidatesPublicSubmitter), storage (PublicFeedbackSubmissionService)
 * and the public page all read this class; none of them restates a rule.
 * Allow-attachments in the city policy (PublicFeedbackSettings) can still turn
 * evidence off; it cannot turn it on for Feedback.
 */
final class PublicSubmissionPolicy
{
    public function for(ServiceFeedbackType $type, bool $attachmentsEnabled = true): SubmissionRequirements
    {
        return match ($type) {
            ServiceFeedbackType::Feedback => new SubmissionRequirements(
                type: $type,
                nameRequired: true,
                phoneRequired: true,
                anonymousAllowed: false,
                tipTypeRequired: false,
                serviceRequired: true,
                narrativeRequired: true,
                evidenceAllowed: false,
                incidentContext: false,
                involvedParties: false,
                submitterAddress: false,
                submitterOrganization: true,
            ),
            ServiceFeedbackType::Suggestion => new SubmissionRequirements(
                type: $type,
                nameRequired: false,
                phoneRequired: false,
                anonymousAllowed: true,
                tipTypeRequired: true,
                serviceRequired: false,
                narrativeRequired: true,
                evidenceAllowed: $attachmentsEnabled,
                incidentContext: true,
                involvedParties: true,
                submitterAddress: true,
                submitterOrganization: false,
            ),
            ServiceFeedbackType::Complaint => new SubmissionRequirements(
                type: $type,
                nameRequired: true,
                phoneRequired: true,
                anonymousAllowed: false,
                tipTypeRequired: false,
                serviceRequired: false,
                narrativeRequired: true,
                evidenceAllowed: $attachmentsEnabled,
                incidentContext: true,
                involvedParties: false,
                submitterAddress: false,
                submitterOrganization: true,
            ),
        };
    }
}
