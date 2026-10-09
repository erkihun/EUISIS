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
        return [
            'position_service_id' => [
                'required',
                'uuid',
                // Existence only; whether this officer's POSITION delivers the
                // service is checked in the controller, which knows the token.
                Rule::exists('position_services', 'id')->whereNull('deleted_at'),
            ],
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
            'rating.between' => 'Please choose a rating between 1 and 5 stars.',
            'rating.required' => 'Please choose a satisfaction rating.',
            'position_service_id.required' => 'Please choose the service you received.',
        ];
    }
}
