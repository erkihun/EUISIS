<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\SystemSetting;
use App\Services\Security\DefaultPasswordPolicyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSecuritySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageSecurity') ?? false;
    }

    public function rules(): array
    {
        $minimumLength = max(8, (int) $this->input('password_min_length', 12));
        $complexityEnabled = $this->boolean('password_complexity_enabled', true);

        return [
            'password_min_length' => ['required', 'integer', 'min:8', 'max:64'],
            'session_timeout_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'max_upload_size_mb' => ['required', 'integer', 'min:1', 'max:50'],
            'password_complexity_enabled' => ['required', 'boolean'],
            'default_password_enabled' => ['sometimes', 'required', 'boolean'],
            'default_password_hash' => [
                'nullable',
                'string',
                'confirmed',
                app(DefaultPasswordPolicyService::class)->rule($minimumLength, $complexityEnabled),
            ],
            'default_password_hash_confirmation' => ['nullable', 'string'],
            'force_change_default_password' => ['sometimes', 'required', 'boolean'],
            'max_login_attempts' => ['required', 'integer', 'min:1', 'max:50'],
            'lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'password_expiry_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'mfa_enabled' => ['required', 'boolean'],
            'mfa_required_for_all' => ['required', 'boolean'],
            'mfa_required_role_ids' => ['nullable', 'array'],
            'mfa_required_role_ids.*' => ['integer', 'exists:roles,id'],
            // Legacy flag: accepted for backward compatibility but no longer
            // sent by the settings UI. When omitted, saving the new MFA
            // settings retires it (see SystemSettingController::updateSecurity).
            'require_mfa_for_admins' => ['sometimes', 'boolean'],
            'force_https' => ['required', 'boolean'],
            'maintenance_banner_enabled' => ['required', 'boolean'],
            'maintenance_banner_message_en' => ['nullable', 'string', 'max:2000'],
            'maintenance_banner_message_am' => ['nullable', 'string', 'max:2000'],
            'allowed_file_types' => ['required', 'array', 'min:1'],
            'allowed_file_types.*' => ['string', 'max:16', 'regex:/^[a-z0-9]+$/i'],
            'allowed_upload_mime_types' => ['required', 'array', 'min:1'],
            'allowed_upload_mime_types.*' => ['string', 'max:100'],
            'audit_retention_days' => ['required', 'integer', 'min:30', 'max:3650'],
            'sensitive_export_requires_reason' => ['required', 'boolean'],
            'api_rate_limit_per_minute' => ['required', 'integer', 'min:30', 'max:10000'],
            'verification_rate_limit_per_minute' => ['required', 'integer', 'min:30', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('default_password_enabled')) {
                    return;
                }

                $hasSubmittedPassword = filled($this->input('default_password_hash'));
                $hasStoredPassword = SystemSetting::query()
                    ->where('group', 'security')
                    ->where('key', 'default_password_hash')
                    ->whereNotNull('value')
                    ->where('value', '!=', '')
                    ->exists();

                if (! $hasSubmittedPassword && ! $hasStoredPassword) {
                    $validator->errors()->add('default_password_hash', __('settings.default_password_required'));
                }
            },
        ];
    }
}
