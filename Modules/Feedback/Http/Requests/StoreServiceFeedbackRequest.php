<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Feedback\Application\Services\PublicFeedbackTargetResolver;
use Modules\Feedback\Application\Services\PublicServiceFeedbackService;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Http\Requests\Concerns\ValidatesOrganizationFormContext;
use Modules\Feedback\Http\Requests\Concerns\ValidatesPublicSubmitter;

/**
 * Validation for a public Feedback submission: a star rating, the service
 * received (employee target) and what the visitor has to say, from a named
 * visitor — Feedback is never anonymous (PublicSubmissionPolicy).
 *
 * Authorisation is intentionally open — the token in the URL is the only
 * credential, and it is checked by the controller before this request is
 * reached. Field limits are tight because everything here is attacker-supplied
 * and some of it is rendered back to administrators.
 */
class StoreServiceFeedbackRequest extends FormRequest
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
        $employeeTarget = $this->routeIs('service-feedback.*');
        $requirements = $this->requirements(ServiceFeedbackType::Feedback);

        return $this->withTypeRules(ServiceFeedbackType::Feedback, [
            'form_token' => ['nullable', 'string', 'size:32'],
            // A service belongs to an employee's position; an organization has none.
            'position_service_id' => $employeeTarget ? [
                // Required only when the officer's position has services to choose
                // from, which the controller knows once the token is resolved: a
                // position with none configured must still be ratable.
                'nullable',
                'uuid',
                // Existence only; whether this officer's POSITION delivers the
                // service is checked in the controller, which knows the token.
                Rule::exists('position_services', 'id')->whereNull('deleted_at'),
            ] : ['prohibited'],
            // The rating, its kind and the window served at are the Service feedback module's fields.
            'comment' => [$requirements->narrativeRequired ? 'required' : 'nullable', 'string', 'max:2000'],
            ...$this->submitterRules($requirements),
            ...$this->evidenceRules($requirements),
            ...$this->organizationContextRules(ServiceFeedbackType::Feedback),
        ]);
    }

    /**
     * The service is required once the token is resolved and the officer's post
     * has services to choose from — checked here, with the other rules, so a
     * visitor sees every problem in one answer. A post with none configured is
     * still ratable: the form showed no list.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        if (! $this->routeIs('service-feedback.*')) {
            return [];
        }

        return [
            function (Validator $validator): void {
                if (filled($this->input('position_service_id'))) {
                    return;
                }

                $context = app(PublicFeedbackTargetResolver::class)->resolveEmployeeToken((string) $this->route('token'));

                if ($context !== null && app(PublicServiceFeedbackService::class)->serviceOptions($context->contextPositionId)->isNotEmpty()) {
                    $validator->errors()->add('position_service_id', __('service-feedback.validation.service_required'));
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->submitterMessages() + $this->organizationContextMessages() + [
            'rating.between' => __('service-feedback.validation.rating_range'),
            'rating.integer' => __('service-feedback.validation.rating_range'),
            'rating.required' => __('service-feedback.validation.rating_required'),
            'comment.required' => __('service-feedback.validation.comment_required'),
            'position_service_id.required' => __('service-feedback.validation.service_required'),
            'position_service_id.uuid' => __('service-feedback.validation.service_required'),
            'position_service_id.exists' => __('service-feedback.service_not_provided'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->organizationContextAttributes(ServiceFeedbackType::Feedback) + $this->typeAttributes(ServiceFeedbackType::Feedback);
    }
}
