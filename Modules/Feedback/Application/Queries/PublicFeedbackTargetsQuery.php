<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Queries;

use Modules\Feedback\Application\Services\PublicEmployeeFeedbackIdentity;
use Modules\Feedback\Application\Services\PublicFeedbackTargetResolver;
use Modules\Feedback\Contracts\Data\PublicFeedbackTarget;
use Modules\Feedback\Contracts\Data\PublicFeedbackTargetType;
use Modules\Feedback\Contracts\PublicFeedbackTargets;

/** The QR pages' own resolver and identity rules, behind the PublicFeedbackTargets contract. */
final class PublicFeedbackTargetsQuery implements PublicFeedbackTargets
{
    public function __construct(private readonly PublicFeedbackTargetResolver $resolver) {}

    public function employee(string $token, string $locale): ?PublicFeedbackTarget
    {
        $context = $this->resolver->resolveEmployeeToken($token);
        if ($context === null) {
            return null;
        }

        // Exactly what the page shows (display name, position title) and nothing else.
        $identity = PublicEmployeeFeedbackIdentity::fromContext($context, $token, $locale)->toArray();

        return new PublicFeedbackTarget(
            type: PublicFeedbackTargetType::Employee,
            name: (string) ($identity['display_name'] ?? ''),
            positionTitle: $identity['position_title'] ?? null,
        );
    }

    public function organization(string $token, string $locale): ?PublicFeedbackTarget
    {
        $context = $this->resolver->resolveOrganizationToken($token);
        if ($context === null) {
            return null;
        }
        $display = $context->publicDisplay($locale);
        $phone = $context->extras['public_contact_phone'] ?? null;

        return new PublicFeedbackTarget(
            type: PublicFeedbackTargetType::Organization,
            name: (string) ($display['display_name'] ?? ''),
            organizationId: $context->organizationTargetId,
            publicContactPhone: is_string($phone) ? $phone : null,
        );
    }
}
