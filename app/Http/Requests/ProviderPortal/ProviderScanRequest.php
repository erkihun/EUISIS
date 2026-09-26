<?php

declare(strict_types=1);

namespace App\Http\Requests\ProviderPortal;

use App\Enums\CafeteriaUsageMode;
use App\Models\ProviderUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();

        return $providerUser?->hasService('cafeteria') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],
            'qr_token' => ['required', 'string', 'min:10'],
            'scan_nonce' => ['required', 'uuid'],
            // The server sets the time: a client time could claim past days.
            'scanned_at' => ['prohibited'],
            'usage_mode' => ['required', Rule::in([
                CafeteriaUsageMode::SingleDay->value,
                CafeteriaUsageMode::UseRemainingWeek->value,
            ])],
            'meal_amount' => ['prohibited'],
            'requested_subsidy_amount' => ['prohibited'],
        ];
    }
}
