<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLegalSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $source = $this->route('source');

        return $this->user()?->can('update', $source) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return LegalSourceRules::rules(partial: true);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return LegalSourceRules::attributes();
    }
}
