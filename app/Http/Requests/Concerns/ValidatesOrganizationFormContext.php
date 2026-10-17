<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\PublicServiceTimeBand;
use App\Enums\ServiceFeedbackType;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaire;
use Illuminate\Validation\Rule;

/**
 * The service information and questionnaire answers of the organization
 * feedback form (docs/organization-public-feedback-form.md §3–4).
 *
 * Shape and limits are checked here. Whether a service or an office belongs
 * to the scanned institution needs the resolved target, so the controller
 * checks that after resolving the token. On the employee QR routes every one
 * of these fields is `prohibited`: an employee's service comes from the
 * employee's post, and a forged field is refused rather than stored.
 */
trait ValidatesOrganizationFormContext
{
    protected function isOrganizationRoute(): bool
    {
        return $this->routeIs('public-feedback.organization.*');
    }

    /** @return array<string, array<int, mixed>> */
    protected function organizationContextRules(ServiceFeedbackType $type): array
    {
        if (! $this->isOrganizationRoute()) {
            // organization_unit_id is left out on purpose: on the employee QR it is the employee's own
            // unit, taken from the current assignment, and a posted value is ignored like any forged target field.
            return array_fill_keys(['service_id', 'service_text', 'service_date', 'service_time_band', 'questionnaire_version', 'answers'], ['prohibited']);
        }

        return [
            'service_id' => ['nullable', 'uuid'],
            'service_text' => ['nullable', 'string', 'max:200'],
            // The office (service point) where the service was received; checked against the institution by the controller.
            'organization_unit_id' => ['nullable', 'uuid'],
            // Recent service only: feedback about a visit years ago is not service feedback.
            'service_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subYear()->toDateString()],
            'service_time_band' => ['nullable', 'string', Rule::enum(PublicServiceTimeBand::class)],
            ...app(PublicFeedbackQuestionnaire::class)->rules($type),
        ];
    }

    /** @return array<string, string> */
    protected function organizationContextMessages(): array
    {
        return [
            'service_id.uuid' => __('service-feedback.service_not_provided'),
            'organization_unit_id.uuid' => __('service-feedback.validation.office_invalid'),
            'service_date.before_or_equal' => __('service-feedback.validation.service_date_future'),
            'service_date.after_or_equal' => __('service-feedback.validation.service_date_too_old'),
            'service_date.date_format' => __('service-feedback.validation.service_date_invalid'),
            // No version stated although questions are asked: the page predates the published questionnaire.
            'questionnaire_version.required' => __('service-feedback.validation.questionnaire_updated'),
            'questionnaire_version.integer' => __('service-feedback.validation.questionnaire_updated'),
            'answers.*.required' => __('service-feedback.validation.answer_required'),
            'answers.*.min' => __('service-feedback.validation.answer_required'),
            'answers.*.between' => __('service-feedback.validation.answer_invalid'),
            'answers.*.integer' => __('service-feedback.validation.answer_invalid'),
            'answers.*.in' => __('service-feedback.validation.answer_invalid'),
            'answers.*.array' => __('service-feedback.validation.answer_invalid'),
            'answers.*.max' => __('service-feedback.validation.answer_too_long'),
            'answers.*.*.in' => __('service-feedback.validation.answer_invalid'),
        ];
    }

    /** @return array<string, string> */
    protected function organizationContextAttributes(ServiceFeedbackType $type): array
    {
        return $this->isOrganizationRoute()
            ? app(PublicFeedbackQuestionnaire::class)->attributes($type, app()->getLocale())
            : [];
    }
}
