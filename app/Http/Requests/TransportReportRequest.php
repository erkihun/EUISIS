<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The report and boarding-list filters, admin and portal; the window is bounded to a year of data per request. */
class TransportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('provider.portal.*')) {
            return auth('provider')->check();
        }

        return $this->user()?->can('transport-reports.view') || $this->user()?->can('transport-transactions.viewAny');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'before_or_equal:'.($this->input('date_from') ? date('Y-m-d', strtotime((string) $this->input('date_from').' +1 year')) : '9999-12-31')],
            'provider_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['accepted', 'rejected'])],
            'route_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'verification_method' => ['nullable', Rule::in(['qr', 'nfc', 'manual_exception'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
