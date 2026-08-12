<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;

class UpdateGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageGeneral') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('system_environment_label') && is_string($this->input('system_environment_label'))) {
            $this->merge([
                'system_environment_label' => strtolower($this->input('system_environment_label')) ?: null,
            ]);
        }
    }

    public function rules(): array
    {
        $maxUploadKilobytes = $this->maxUploadKilobytes();

        return [
            'application_name' => ['required', 'string', 'max:160'],
            'application_short_name' => ['required', 'string', 'max:80'],
            'organization_name' => ['required', 'string', 'max:200'],
            'default_dashboard_route' => [
                'required',
                'string',
                'max:120',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! Route::has($value)) {
                        $fail('The selected dashboard route is invalid.');
                    }
                },
            ],
            'support_email' => ['nullable', 'email', 'max:160'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'system_environment_label' => ['nullable', 'string', 'in:production,staging,testing,local,development,demo,training'],
            'help_center_url' => ['nullable', 'url', 'max:255'],
            'privacy_policy_url' => ['nullable', 'url', 'max:255'],
            'terms_url' => ['nullable', 'url', 'max:255'],
            'login_page_message_en' => ['nullable', 'string', 'max:2000'],
            'login_page_message_am' => ['nullable', 'string', 'max:2000'],
            'identity_system_logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxUploadKilobytes],
            'favicon' => ['nullable', 'file', 'mimes:ico,png,webp', 'max:'.$maxUploadKilobytes],
            'seal' => ['nullable', 'file', 'image', 'mimes:png', 'max:'.$maxUploadKilobytes],
        ];
    }

    private function maxUploadKilobytes(): int
    {
        /** @var SystemSettingsService $settingsService */
        $settingsService = app(SystemSettingsService::class);

        return max(1, (int) $settingsService->get('security', 'max_upload_size_mb', 10)) * 1024;
    }
}
