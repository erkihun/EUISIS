<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Requests\Concerns;

use App\Rules\PublicPhoneNumber;
use App\Rules\SafeUploadName;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Modules\Feedback\Application\Services\PublicEvidencePolicy;
use Modules\Feedback\Application\Services\PublicFeedbackSettings;
use Modules\Feedback\Application\Services\PublicSubmissionPolicy;
use Modules\Feedback\Application\Services\SubmissionRequirements;
use Modules\Feedback\Contracts\Data\PublicTipType;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Contracts\PublicSubmissionTypes;
use Modules\Feedback\Http\Rules\PublicEvidenceFile;

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

    /**
     * The intake's rules with the type's own form fields (its module, ADR 0032)
     * on top; a field only another type has is refused, so a forged field is
     * not silently stored.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    protected function withTypeRules(ServiceFeedbackType $type, array $base): array
    {
        $types = app(PublicSubmissionTypes::class);
        $own = $types->for($type)->rules();
        $foreign = [];
        foreach ($types->all() as $other) {
            if ($other->type() === $type) {
                continue;
            }
            foreach (array_keys($other->rules()) as $key) {
                $top = explode('.', $key)[0];
                if (! array_key_exists($top, $base) && ! array_key_exists($top, $own)) {
                    $foreign[$top] = ['prohibited'];
                }
            }
        }

        return [...$base, ...$foreign, ...$own];
    }

    /** @return array<string, string> */
    protected function typeAttributes(ServiceFeedbackType $type): array
    {
        return app(PublicSubmissionTypes::class)->for($type)->attributes();
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
            'submitter_address' => $requirements->submitterAddress ? ['nullable', 'array:city,sub_city,woreda,detail,house_number'] : ['prohibited'],
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
