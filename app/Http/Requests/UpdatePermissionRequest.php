<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Permission|null $permission */
        $permission = $this->route('permission');

        return $this->user()?->can('update', $permission ?? Permission::class) ?? false;
    }

    public function rules(): array
    {
        /** @var Permission|null $permission */
        $permission = $this->route('permission');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('permissions', 'name')->ignore($permission?->id)],
            'label_en' => ['nullable', 'string', 'max:100'],
            'label_am' => ['nullable', 'string', 'max:100'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_am' => ['nullable', 'string', 'max:500'],
            'group' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
