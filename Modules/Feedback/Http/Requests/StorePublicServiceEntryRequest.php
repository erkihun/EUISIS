<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Feedback\Application\Services\PublicComplaintForm;
use Modules\Feedback\Application\Services\PublicTipForm;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Http\Requests\Concerns\ValidatesOrganizationFormContext;
use Modules\Feedback\Http\Requests\Concerns\ValidatesPublicSubmitter;

/**
 * Validation for a public Tip-off (internal type `suggestion`) or Complaint.
 *
 * The route names the type; the token in the URL is the only credential and
 * is resolved by the controller, so nothing here can name a target. What each
 * type requires comes from PublicSubmissionPolicy: a Tip-off requires its type
 * and the facts, never the tipster's identity or a service received; a
 * Complaint requires the complainant's name and phone and the facts.
 */
class StorePublicServiceEntryRequest extends FormRequest
{
    use ValidatesOrganizationFormContext;
    use ValidatesPublicSubmitter;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->route('type') === 'complaint' ? ServiceFeedbackType::Complaint : ServiceFeedbackType::Suggestion;
        $requirements = $this->requirements($type);
        $employeeTarget = $this->routeIs('service-feedback.*');

        return [
            'form_token' => ['nullable', 'string', 'size:32'],
            'category_id' => ['nullable', 'uuid'],
            // Optional context only: a Tip-off never needs a service to have been received.
            'position_service_id' => $employeeTarget
                ? ['nullable', 'uuid', Rule::exists('position_services', 'id')->whereNull('deleted_at')]
                : ['prohibited'],
            // The one narrative field: the complaint's details, or the Tip-off's facts.
            'message' => [$requirements->narrativeRequired ? 'required' : 'nullable', 'string', 'max:5000'],
            ...$this->submitterRules($requirements),
            // The browser form sends both keys empty when unknown; `nullable` lets them through.
            'incident_date' => $requirements->incidentContext ? ['nullable', 'date', 'before_or_equal:today'] : ['prohibited'],
            'incident_location' => $requirements->incidentContext ? ['nullable', 'string', 'max:255'] : ['prohibited'],
            ...$this->evidenceRules($requirements),
            ...$this->organizationContextRules($type),
            // The complaint form's sections (owner's template); replaces the optional address rules above.
            ...($requirements->complaintForm ? PublicComplaintForm::rules() : ['complaint_form' => ['prohibited']]),
            // The Tip-off form's optional parts (owner's sample).
            ...($requirements->tipForm ? PublicTipForm::rules() : ['tip_form' => ['prohibited']]),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->submitterMessages() + $this->organizationContextMessages() + [
            'message.required' => __('service-feedback.validation.message_required'),
            'position_service_id.uuid' => __('service-feedback.validation.service_required'),
            'position_service_id.exists' => __('service-feedback.service_not_provided'),
            'incident_date.before_or_equal' => __('service-feedback.validation.incident_date_future'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = $this->organizationContextAttributes($this->route('type') === 'complaint' ? ServiceFeedbackType::Complaint : ServiceFeedbackType::Suggestion);
        foreach (['gender', 'id_type', 'id_number', 'alt_phone', 'principal_name', 'poa_reference', 'poa_date', 'remedy_sought', 'declaration', 'prior_woreda_date', 'prior_sub_city_date', 'other_pages'] as $field) {
            $attributes["complaint_form.{$field}"] = __("service-feedback.complaint_form.{$field}");
        }

        foreach (['suspect_name', 'suspect_unit', 'suspect_location', 'violation_other', 'evidence_description'] as $field) {
            $attributes["tip_form.{$field}"] = __("service-feedback.tip_form.{$field}");
        }

        return $attributes + [
            'submitter_address.sub_city' => __('service-feedback.complaint_form.sub_city'),
            'submitter_address.woreda' => __('service-feedback.complaint_form.woreda'),
        ];
    }
}
