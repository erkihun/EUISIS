<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProviderUser;
use Illuminate\Foundation\Http\FormRequest;

/** Report and transaction filters, for the admin pages and the provider portal. */
class TransportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('provider.portal.*')) {
            /** @var ProviderUser|null $providerUser */
            $providerUser = auth('provider')->user();

            return $providerUser?->canUseServicePermission('provider.transport.reports.view') ?? false;
        }

        $user = auth('web')->user();

        return $user !== null && $user->canAny(['transport-reports.view', 'transport-transactions.viewAny']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            // Only the admin pages choose a provider; the portal is always its own.
            'provider_id' => $this->routeIs('provider.portal.*') ? ['prohibited'] : ['nullable', 'uuid'],
            'status' => ['nullable', 'in:accepted,rejected'],
        ];
    }
}
