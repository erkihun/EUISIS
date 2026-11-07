<?php

declare(strict_types=1);

namespace Modules\TipOffs\Application\Services;

use App\Enums\CodeRuleEntityType;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\Extensions\PublicSubmissionType;

/**
 * The Tip-off, as the public intake takes it (ADR 0032). It follows the paper
 * Tip-off intake form: the tipster's name, phone, e-mail and address are
 * voluntary, and a person may report what they observed without having
 * received a service at all. The internal type value stays `suggestion`.
 */
final class TipOffSubmissionType implements PublicSubmissionType
{
    public function type(): ServiceFeedbackType
    {
        return ServiceFeedbackType::Suggestion;
    }

    public function table(): string
    {
        return 'public_tip_offs';
    }

    public function requirements(bool $attachmentsEnabled): array
    {
        return [
            'nameRequired' => false,
            'phoneRequired' => false,
            'anonymousAllowed' => true,
            'tipTypeRequired' => true,
            'serviceRequired' => false,
            'narrativeRequired' => true,
            'evidenceAllowed' => $attachmentsEnabled,
            'incidentContext' => true,
            'involvedParties' => true,
            'submitterAddress' => true,
            'submitterOrganization' => false,
        ];
    }

    public function rules(): array
    {
        return PublicTipForm::rules();
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach (['suspect_name', 'suspect_unit', 'suspect_location', 'violation_other', 'evidence_description'] as $field) {
            $attributes["tip_form.{$field}"] = __("service-feedback.tip_form.{$field}");
        }

        return $attributes;
    }

    public function storedAttributes(array $data): array
    {
        return ['tip_details' => PublicTipForm::details(is_array($data['tip_form'] ?? null) ? $data['tip_form'] : [])];
    }

    public function codeRule(): CodeRuleEntityType
    {
        return CodeRuleEntityType::PublicTipOff;
    }

    public function tracking(): ?string
    {
        // An anonymous tipster has only the reference.
        return 'reference';
    }
}
