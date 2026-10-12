<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\SubmitterIdentityMode;
use App\Rules\PublicContact;
use App\Services\ServiceFeedback\PublicFeedbackSettings;

/**
 * Submitter identity and attachment rules driven by the public feedback
 * policy (brief §34–35, §93). Anonymous mode validates nothing about the
 * person — the submission service then stores nothing either.
 */
trait ValidatesPublicSubmitter
{
    /** @return array<string, array<int, mixed>> */
    protected function submitterRules(): array
    {
        return match (app(PublicFeedbackSettings::class)->identityMode()) {
            SubmitterIdentityMode::Anonymous => [
                'client_name' => ['nullable', 'string', 'max:120'],
                'client_contact' => ['nullable', 'string', 'max:120'],
            ],
            SubmitterIdentityMode::Identified => [
                'client_name' => ['required', 'string', 'min:2', 'max:120'],
                'client_contact' => ['required', 'string', 'max:120', new PublicContact],
            ],
            SubmitterIdentityMode::Optional => [
                'client_name' => ['nullable', 'string', 'max:120'],
                'client_contact' => ['nullable', 'string', 'max:120', new PublicContact],
            ],
        };
    }

    /** @return array<int, string> */
    protected function attachmentsRule(bool $complaint): array
    {
        return $complaint && app(PublicFeedbackSettings::class)->allowAttachments()
            ? ['sometimes', 'array', 'max:3']
            : ['prohibited'];
    }

    /** @return array<string, string> */
    protected function submitterMessages(): array
    {
        return [
            'client_name.required' => __('service-feedback.validation.name_required'),
            'client_name.min' => __('service-feedback.validation.name_required'),
            'client_contact.required' => __('service-feedback.validation.contact_required'),
            'attachments.prohibited' => __('service-feedback.validation.attachments_disabled'),
        ];
    }
}
