<?php

declare(strict_types=1);

namespace App\Http\Requests\SituationRoom;

use App\Enums\SituationActionPriority;
use App\Enums\SituationActionStatus;
use App\Models\SituationAction;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SituationRoom\SituationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update an executive action. The organization must be inside the
 * user's scope; on update it cannot be moved, and a final status needs an
 * outcome note so the register says what happened.
 */
final class SaveSituationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route('action');

        return $action instanceof SituationAction
            ? (bool) $this->user()?->can('update', $action)
            : (bool) $this->user()?->can('create', SituationAction::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $updating = $this->route('action') instanceof SituationAction;

        return [
            'organization_id' => $updating ? ['prohibited'] : ['required', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
            'category' => ['required', Rule::in(array_column(SituationCategory::cases(), 'value'))],
            'panel_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(SituationActionPriority::values())],
            'responsible_office' => ['nullable', 'string', 'max:200'],
            'due_date' => ['nullable', 'date_format:Y-m-d', ...($updating ? [] : ['after_or_equal:today'])],
            'status' => $updating ? ['required', Rule::in(SituationActionStatus::values())] : ['prohibited'],
            'outcome_note' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn (): bool => in_array($this->input('status'), [SituationActionStatus::Done->value, SituationActionStatus::Cancelled->value], true))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $organization = $this->input('organization_id');

            if (! $validator->errors()->has('organization_id') && is_string($organization)
                && ! app(OrganizationScopeService::class)->canAccessOrganization($this->user(), $organization)) {
                $validator->errors()->add('organization_id', __('validation.exists', ['attribute' => 'organization']));
            }
        }];
    }
}
