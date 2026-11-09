<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;

class StorePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Permission::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:permissions,name'],
            'label_en' => ['nullable', 'string', 'max:100'],
            'label_am' => ['nullable', 'string', 'max:100'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_am' => ['nullable', 'string', 'max:500'],
            'group' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
