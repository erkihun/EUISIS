<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ServiceFeedbackType;
use App\Http\Requests\Concerns\ValidatesPublicSubmitter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->submitterMessages() + [
            'message.required' => __('service-feedback.validation.message_required'),
            'position_service_id.uuid' => __('service-feedback.validation.service_required'),
            'position_service_id.exists' => __('service-feedback.service_not_provided'),
            'incident_date.before_or_equal' => __('service-feedback.validation.incident_date_future'),
        ];
    }
}
