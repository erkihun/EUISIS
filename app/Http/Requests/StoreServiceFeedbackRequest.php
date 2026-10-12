<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for an anonymous client feedback submission.
 *
 * Authorisation is intentionally open — the token in the URL is the only
 * credential, and it is checked by the controller before this request is
 * reached. Field limits are tight because everything here is attacker-supplied
 * and some of it is rendered back to administrators.
 */
class StoreServiceFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $employeeTarget = $this->routeIs('service-feedback.*');

        return [
            'form_token' => ['nullable', 'string', 'size:32'],
            // Applicability (type, target, scope) is checked in the controller.
            'category_id' => ['nullable', 'uuid'],
            // A service belongs to an employee's position; an organization has none.
            'position_service_id' => $employeeTarget ? [
                'required',
                'uuid',
                // Existence only; whether this officer's POSITION delivers the
                // service is checked in the controller, which knows the token.
                Rule::exists('position_services', 'id')->whereNull('deleted_at'),
            ] : ['prohibited'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'client_name' => ['nullable', 'string', 'max:120'],
            'client_contact' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.between' => __('service-feedback.validation.rating_range'),
            'rating.integer' => __('service-feedback.validation.rating_range'),
            'rating.required' => __('service-feedback.validation.rating_required'),
            'position_service_id.required' => __('service-feedback.validation.service_required'),
            'position_service_id.uuid' => __('service-feedback.validation.service_required'),
            'position_service_id.exists' => __('service-feedback.service_not_provided'),
        ];
    }
}
