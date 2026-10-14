<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackType;
use App\Rules\PublicEvidenceFile;
use App\Rules\PublicPhoneNumber;
use App\Rules\SafeUploadName;
use App\Services\ServiceFeedback\PublicEvidencePolicy;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use App\Services\ServiceFeedback\PublicSubmissionPolicy;
use App\Services\ServiceFeedback\SubmissionRequirements;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The submitter, Tip-off and evidence rules of a public form, taken from the
 * one policy matrix (PublicSubmissionPolicy). Feedback and Complaint require
 * the submitter's name and phone; a Tip-off asks for nothing about the
 * tipster and requires its type. Fields that do not belong to a type are
 * `prohibited`, so a forged field is refused rather than silently stored.
 */
trait ValidatesPublicSubmitter
{
    protected function requirements(ServiceFeedbackType $type): SubmissionRequirements
    {
        return app(PublicSubmissionPolicy::class)->for($type, app(PublicFeedbackSettings::class)->allowAttachments());
    }

    /** @return array<string, array<int, mixed>> */
    protected function submitterRules(SubmissionRequirements $requirements): array
    {
        $rules = [
            'client_name' => $requirements->nameRequired
                ? ['required', 'string', 'min:2', 'max:120']
                : ['nullable', 'string', 'max:120'],
            // The submitter's phone. The column predates Tip-offs and once held "phone or e-mail".
            'client_contact' => [$requirements->phoneRequired ? 'required' : 'nullable', 'string', 'max:40', new PublicPhoneNumber],
            'client_email' => ['nullable', 'string', 'max:190', 'email:rfc'],
            'submitter_organization' => $requirements->submitterOrganization ? ['nullable', 'string', 'max:200'] : ['prohibited'],
            'submitter_address' => $requirements->submitterAddress ? ['nullable', 'array:city,sub_city,woreda,detail'] : ['prohibited'],
            'tip_type' => $requirements->tipTypeRequired
                ? ['required', 'string', Rule::enum(PublicTipType::class)]
                : ['prohibited'],
            'involved_parties' => $requirements->involvedParties ? ['nullable', 'string', 'max:2000'] : ['prohibited'],
        ];

        if ($requirements->submitterAddress) {
            $rules += [
                'submitter_address.city' => ['nullable', 'string', 'max:100'],
                'submitter_address.sub_city' => ['nullable', 'string', 'max:100'],
                'submitter_address.woreda' => ['nullable', 'string', 'max:100'],
                'submitter_address.detail' => ['nullable', 'string', 'max:255'],
            ];
        }

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    protected function evidenceRules(SubmissionRequirements $requirements): array
    {
        if (! $requirements->evidenceAllowed) {
            return ['attachments' => ['prohibited']];
        }

        return [
            'attachments' => ['sometimes', 'array', 'max:'.PublicEvidencePolicy::MAX_FILES, function (string $attribute, mixed $files, Closure $fail): void {
                $total = array_sum(array_map(
                    fn (mixed $file): int => $file instanceof UploadedFile ? (int) $file->getSize() : 0,
                    is_array($files) ? $files : [],
                ));

                if ($total > PublicEvidencePolicy::MAX_TOTAL_KB * 1024) {
                    $fail(__('service-feedback.validation.attachments_total', ['max' => intdiv(PublicEvidencePolicy::MAX_TOTAL_KB, 1024)]));
                }
            }],
            'attachments.*' => ['bail', 'file', new SafeUploadName, new PublicEvidenceFile],
        ];
    }

    /** @return array<string, string> */
    protected function submitterMessages(): array
    {
        return [
            'client_name.required' => __('service-feedback.validation.name_required'),
            'client_name.min' => __('service-feedback.validation.name_required'),
            'client_contact.required' => __('service-feedback.validation.contact_required'),
            'client_email.email' => __('service-feedback.validation.email_invalid'),
            'tip_type.required' => __('service-feedback.validation.tip_type_required'),
            'tip_type.enum' => __('service-feedback.validation.tip_type_invalid'),
            'attachments.prohibited' => __('service-feedback.validation.attachments_disabled'),
            'attachments.max' => __('service-feedback.validation.attachments_max', ['max' => PublicEvidencePolicy::MAX_FILES]),
            'attachments.*.file' => __('service-feedback.validation.attachment_type'),
        ];
    }
}
