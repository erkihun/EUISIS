<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CodeRules\PreviewCodeRuleAction;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\CodeRuleScopeType;
use App\Exceptions\MissingSequenceScopeContextException;
use App\Exceptions\MissingTokenContextException;
use App\Models\CodeRule;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Models\ServiceType;
use App\Services\CodeGeneration\CodeFormatTokenRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCodeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $codeRule = $this->route('codeRule');

        return $codeRule instanceof CodeRule
            && ($this->user()?->can('update', $codeRule) ?? false);
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::enum(CodeRuleEntityType::class)],
            'scope_type' => ['nullable', Rule::enum(CodeRuleScopeType::class)],
            'scope_id' => ['nullable', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_.\/]*$/'],
            'suffix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_.\/]*$/'],
            'separator' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_.\/]*$/'],
            'format' => ['required', 'string', 'max:255'],
            'sequence_length' => ['required', 'integer', 'min:1', 'max:10'],
            'next_number' => ['required', 'integer', 'min:1'],
            'reset_frequency' => ['required', Rule::enum(CodeRuleResetFrequency::class)],
            'year_format' => ['nullable', 'string', 'max:10'],
            'is_active' => ['required', 'boolean'],
            'allow_manual_override' => ['required', 'boolean'],
            'require_approval_for_override' => ['required', 'boolean'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'sequence_scope_strategy' => ['nullable', Rule::enum(CodeRuleScopeStrategy::class)],
            'sequence_scope_tokens' => ['nullable', 'array'],
            'sequence_scope_tokens.*' => ['string'],
        ];
    }

    public function after(): array
    {
        return [
            function (): void {
                $validator = $this->validator;
                /** @var CodeRule $codeRule */
                $codeRule = $this->route('codeRule');

                $format = $this->string('format')->toString();

                if (! is_string($this->input('format')) || (! str_contains($format, '{SEQUENCE}') && ! str_contains($format, '{SEQUENCE_PADDED}') && ! str_contains($format, '{RAND_6}'))) {
                    $validator->errors()->add('format', __('code-rules.format_must_contain_sequence'));
                }

                // Validate that all tokens in the format are known
                preg_match_all('/\{([A-Z0-9_]+)\}/', $format, $tokenMatches);
                $registry = app(CodeFormatTokenRegistry::class);

                foreach ($tokenMatches[1] as $tokenKey) {
                    if (! $registry->has($tokenKey)) {
                        $validator->errors()->add('format', __('code-rules.invalid_token_in_format', ['token' => "{{$tokenKey}}"]));
                    }
                }

                if ($this->filled('scope_id') && ! $this->filled('scope_type')) {
                    $validator->errors()->add('scope_type', __('validation.required'));
                }

                if (! $this->scopeTargetExists()) {
                    $validator->errors()->add('scope_id', __('validation.exists'));
                }

                if ((bool) $this->input('is_active', true) && CodeRule::query()
                    ->whereKeyNot($codeRule->getKey())
                    ->where('entity_type', $this->string('entity_type')->toString())
                    ->where('scope_type', $this->input('scope_type'))
                    ->where('scope_id', $this->input('scope_id'))
                    ->where('is_active', true)
                    ->exists()) {
                    $validator->errors()->add('entity_type', __('code-rules.duplicate_active_rule'));
                }

                $this->validateSequenceScopeTokens($validator, $format);

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $preview = app(PreviewCodeRuleAction::class)->execute($this->validated());
                } catch (MissingSequenceScopeContextException|MissingTokenContextException) {
                    $preview = null;
                }

                if ($preview === '') {
                    $validator->errors()->add('format', __('code-rules.invalid_format'));
                }
            },
        ];
    }

    private function validateSequenceScopeTokens(Validator $validator, string $format): void
    {
        if ($this->string('sequence_scope_strategy')->toString() !== CodeRuleScopeStrategy::CustomTokens->value) {
            return;
        }

        $tokens = (array) ($this->input('sequence_scope_tokens') ?? []);

        if (empty($tokens)) {
            $validator->errors()->add('sequence_scope_tokens', __('code-rules.custom_tokens_required'));

            return;
        }

        $excluded = ['SEQUENCE', 'SEQUENCE_PADDED', 'RAND_6'];

        foreach ($tokens as $token) {
            if (in_array($token, $excluded, true)) {
                $validator->errors()->add('sequence_scope_tokens', __('code-rules.custom_tokens_cannot_include_sequence'));

                return;
            }

            if (! str_contains($format, "{{$token}}")) {
                $validator->errors()->add('sequence_scope_tokens', __('code-rules.custom_tokens_must_exist_in_format', ['token' => $token]));

                return;
            }
        }
    }

    private function scopeTargetExists(): bool
    {
        if (! $this->filled('scope_type') || ! $this->filled('scope_id')) {
            return true;
        }

        $scopeType = $this->string('scope_type')->toString();
        $scopeId = $this->string('scope_id')->toString();

        return match ($scopeType) {
            CodeRuleScopeType::Organization->value => Organization::query()->whereKey($scopeId)->exists(),
            CodeRuleScopeType::OrganizationType->value => OrganizationType::query()->whereKey($scopeId)->exists(),
            CodeRuleScopeType::OrganizationUnitType->value => OrganizationUnitType::query()->whereKey($scopeId)->exists(),
            CodeRuleScopeType::ServiceType->value => ServiceType::query()->whereKey($scopeId)->exists(),
            default => false,
        };
    }
}
