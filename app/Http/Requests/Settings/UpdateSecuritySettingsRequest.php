<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSecuritySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageSecurity') ?? false;
    }

    public function rules(): array
    {
        $floor = (int) config('security.passwords.minimum_length_floor', 15);
        $ceiling = (int) config('security.passwords.maximum_length_ceiling', 128);

        return [
            /*
             * Password policy (docs/password-security-policy.md). The minimum
             * can be raised, never lowered below the approved floor; there are
             * no composition rules and no periodic expiry to configure.
             */
            'password_min_length' => ['required', 'integer', 'min:'.$floor, 'max:'.$ceiling, 'lte:password_max_length'],
            'password_max_length' => ['required', 'integer', 'min:'.config('security.passwords.maximum_length_floor', 64), 'max:'.$ceiling],
            'password_history_count' => ['required', 'integer', 'min:0', 'max:'.config('security.passwords.history_max', 24)],
            'password_block_personal_info' => ['required', 'boolean'],
            'password_block_common' => ['required', 'boolean'],
            'password_breach_check' => ['required', 'boolean'],
            // The legacy shared default password is read-only: it can no
            // longer be set or re-enabled (docs/password-security-policy.md).
            'default_password_enabled' => ['prohibited'],
            'default_password_hash' => ['prohibited'],
            'session_timeout_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'max_upload_size_mb' => ['required', 'integer', 'min:1', 'max:50'],
            'max_login_attempts' => ['required', 'integer', 'min:1', 'max:50'],
            'lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
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
}
