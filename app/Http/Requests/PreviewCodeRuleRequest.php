<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\CodeRuleScopeType;
use App\Models\CodeRule;
use App\Services\CodeGeneration\CodeFormatTokenRegistry;
use App\Services\CodeGeneration\CodeFormatTokenResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewCodeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('preview', CodeRule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::enum(CodeRuleEntityType::class)],
            'scope_type' => ['nullable', Rule::enum(CodeRuleScopeType::class)],
            'scope_id' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_.\/]*$/'],
            'suffix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_.\/]*$/'],
            'format' => ['required', 'string', 'max:255'],
            'separator' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_.\/]*$/'],
            'sequence_length' => ['required', 'integer', 'min:1', 'max:10'],
            'next_number' => ['required', 'integer', 'min:1'],
            'reset_frequency' => ['required', Rule::enum(CodeRuleResetFrequency::class)],
            'year_format' => ['nullable', 'string', 'max:10'],
            // Context fields for token resolution
            'organization_id' => ['nullable', 'string', 'max:255'],
            'organization_type_id' => ['nullable', 'string', 'max:255'],
            'parent_organization_id' => ['nullable', 'string', 'max:255'],
            'organization_unit_id' => ['nullable', 'string', 'max:255'],
            'organization_unit_type_id' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'position_id' => ['nullable', 'string', 'max:255'],
            'service_type_id' => ['nullable', 'string', 'max:255'],
            'service_provider_id' => ['nullable', 'string', 'max:255'],
            'request_type' => ['nullable', 'string', 'max:100'],
            'workflow_code' => ['nullable', 'string', 'max:100'],
            'approval_step_code' => ['nullable', 'string', 'max:100'],
            'document_type_code' => ['nullable', 'string', 'max:100'],
            'custom' => ['nullable', 'string', 'max:100'],
            'custom_1' => ['nullable', 'string', 'max:100'],
            'custom_2' => ['nullable', 'string', 'max:100'],
            'custom_3' => ['nullable', 'string', 'max:100'],
            'sequence_scope_strategy' => ['nullable', Rule::enum(CodeRuleScopeStrategy::class)],
            'sequence_scope_tokens' => ['nullable', 'array'],
            'sequence_scope_tokens.*' => ['string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $format = $this->string('format')->toString();

                if (! str_contains($format, '{SEQUENCE}')
                    && ! str_contains($format, '{SEQUENCE_PADDED}')
                    && ! CodeFormatTokenResolver::usesRandomToken($format)) {
                    $validator->errors()->add('format', __('code-rules.format_must_contain_sequence'));
                }

                preg_match_all('/\{([A-Z0-9_]+)\}/', $format, $matches);
                $registry = app(CodeFormatTokenRegistry::class);

                foreach ($matches[1] as $token) {
                    if (! $registry->has($token)) {
                        $validator->errors()->add('format', __('code-rules.invalid_token_in_format', ['token' => "{{$token}}"]));
                    }
                }
            },
        ];
    }
}
