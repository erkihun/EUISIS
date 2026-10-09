<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationTypeShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('organizationType')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
