<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReceptionReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.operate') ?? false;
    }

    public function rules(): array
    {
        return ['to_organization_id' => ['required', 'uuid', 'exists:organizations,id'], 'to_organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'], 'service_id' => ['nullable', 'uuid', 'exists:services,id'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
