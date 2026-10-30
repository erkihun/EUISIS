<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('serviceProvider'));
    }

    public function rules(): array
    {
        $id = $this->route('serviceProvider')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('service_providers', 'code')->ignore($id)],
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'status' => ['required', 'string', 'in:active,inactive,suspended'],
            'is_demo' => ['boolean'],
        ];
    }
}
