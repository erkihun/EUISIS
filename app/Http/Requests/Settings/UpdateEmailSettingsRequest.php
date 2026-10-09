<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageEmail') ?? false;
    }

    public function rules(): array
    {
        return [
            'mail_mailer' => ['required', Rule::in(['smtp', 'log', 'sendmail', 'ses'])],
            'mail_host' => ['nullable', 'string', 'max:160'],
            'mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'mail_from_address' => ['nullable', 'email', 'max:160'],
            'mail_from_name' => ['nullable', 'string', 'max:160'],
            'mail_username' => ['nullable', 'string', 'max:200'],
            'mail_password' => ['nullable', 'string', 'max:400'],
            'email_test_recipient' => ['nullable', 'email', 'max:160'],
        ];
    }

    /**
     * Port and encryption must agree, or no message is ever delivered: SSL
     * means TLS from the first byte (port 465), TLS means a plain connection
     * upgraded with STARTTLS (587 or 25).
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $port = (int) $this->input('mail_port');
            $encryption = (string) $this->input('mail_encryption');

            if ($port === 0 || $this->input('mail_mailer') !== 'smtp') {
                return;
            }

            if ($encryption === 'ssl' && $port !== 465) {
                $validator->errors()->add('mail_port', __('settings.messages.mail_port_ssl'));
            } elseif ($encryption !== 'ssl' && $port === 465) {
                $validator->errors()->add('mail_encryption', __('settings.messages.mail_port_465'));
            }
        }];
    }
}
