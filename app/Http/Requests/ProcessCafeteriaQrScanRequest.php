<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CafeteriaUsageMode;
use App\Models\CafeteriaTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessCafeteriaQrScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scan', CafeteriaTransaction::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Exactly one credential is supplied: a scanned QR token, or an
            // NFC credential reference read by the attended terminal.
            'qr_token' => ['required_without:nfc_credential', 'nullable', 'string', 'min:10'],
            'nfc_credential' => ['required_without:qr_token', 'nullable', 'string', 'regex:/\Anfc_[a-f0-9]{64}\z/'],
            'provider_id' => ['required', 'uuid', 'exists:cafeteria_providers,id'],
            'scan_nonce' => ['required', 'uuid'],
            // The server sets the time and every amount (docs/cafeteria-policy-architecture.md).
            'scanned_at' => ['prohibited'],
            'usage_mode' => ['required', Rule::in([
                CafeteriaUsageMode::SingleDay->value,
                CafeteriaUsageMode::UseRemainingWeek->value,
            ])],
            'meal_amount' => ['prohibited'],
            'source' => ['nullable', 'in:desktop,mobile'],
            'requested_subsidy_amount' => ['prohibited'],
        ];
    }
}
