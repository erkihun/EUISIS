<?php

declare(strict_types=1);

namespace Modules\Court\Http\Requests\Admin;

use Modules\Court\Domain\Models\CourtCase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDecisionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'case_id' => [
                'required',
                Rule::exists(CourtCase::class, 'id')
                    ->where('status', 'closed'),
            ],
            'case_file_number' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'decision_content' => ['required', 'string'],
            'decision_date' => ['required', 'date'],
            'reviewing_admin_user_names' => ['nullable', 'array'],
            'reviewing_admin_user_names.*' => ['string', 'max:255'],
            'judges_comments' => ['nullable', 'string', 'max:5000'],
            ...self::panelRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'case_id.exists' => __('court::decisions.validation.closed_case_required'),
            ...self::panelMessages(),
        ];
    }

    /**
     * A decision must name three different judges, and the presiding (middle)
     * seat must be held by the judge submitting it. Shared with the update
     * path so a panel can never be emptied after creation.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function panelRules(): array
    {
        return [
            'judges' => ['required', 'array', 'size:3'],
            'judges.*' => ['required', 'array'],
            'judges.*.admin_user_id' => ['required', 'integer', 'distinct', 'exists:court_staff,id'],
            'judges.1.admin_user_id' => [Rule::in([auth()->id()])],
        ];
    }

    /** @return array<string, string> */
    public static function panelMessages(): array
    {
        return [
            'judges.required' => __('court::decisions.validation.panel_required'),
            'judges.size' => __('court::decisions.validation.panel_required'),
            'judges.*.required' => __('court::decisions.validation.panel_required'),
            'judges.*.admin_user_id.required' => __('court::decisions.validation.panel_required'),
            'judges.*.admin_user_id.distinct' => __('court::decisions.validation.panel_distinct'),
            'judges.1.admin_user_id.in' => __('court::decisions.validation.presiding_judge_must_be_you'),
        ];
    }
}
