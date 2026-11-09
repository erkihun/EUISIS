<?php

declare(strict_types=1);

namespace Modules\ServiceFeedback\Application\Services;

use App\Enums\CodeRuleEntityType;
use Illuminate\Validation\Rule;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\Extensions\PublicSubmissionType;

/**
 * Service feedback, as the public intake takes it (ADR 0032; owner's sample
 * form 2026-11-08): a 1–5 rating, the kind of feedback (constructive advice,
 * an idea to improve how the institution works, thanks), the work unit or
 * window served at, and the comment. Name and phone are optional.
 */
final class ServiceFeedbackSubmissionType implements PublicSubmissionType
{
    /** Kinds of service feedback (owner's sample form). */
    public const KINDS = ['constructive', 'improvement', 'appreciation'];

    public function type(): ServiceFeedbackType
    {
        return ServiceFeedbackType::Feedback;
    }

    public function table(): string
    {
        return 'public_service_feedback';
    }

    public function requirements(bool $attachmentsEnabled): array
    {
        return [
            'nameRequired' => false,
            'phoneRequired' => false,
            'anonymousAllowed' => true,
            'tipTypeRequired' => false,
            'serviceRequired' => true,
            'narrativeRequired' => true,
            // A rating never carries evidence, whatever the city policy says.
            'evidenceAllowed' => false,
            'incidentContext' => false,
            'involvedParties' => false,
            'submitterAddress' => false,
            'submitterOrganization' => true,
        ];
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'feedback_kind' => ['nullable', Rule::in(self::KINDS)],
            'service_point' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return [];
    }

    public function storedAttributes(array $data): array
    {
        $point = is_string($data['service_point'] ?? null) ? trim($data['service_point']) : '';

        return [
            'rating' => (int) ($data['rating'] ?? 0),
            'feedback_kind' => in_array($data['feedback_kind'] ?? null, self::KINDS, true) ? $data['feedback_kind'] : null,
            'service_point' => $point === '' ? null : $point,
        ];
    }

    public function codeRule(): CodeRuleEntityType
    {
        return CodeRuleEntityType::PublicServiceFeedback;
    }

    public function tracking(): ?string
    {
        // Feedback is not a case to follow up.
        return null;
    }
}
