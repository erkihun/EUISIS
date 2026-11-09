<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Process;
use Illuminate\Foundation\Http\FormRequest;

/** A new version starts from the published content; only what changes is sent. */
class NewProcessVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('version', $this->route('process')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Process $process */
        $process = $this->route('process');
        $rules = ProcessRules::rules($process->organization_id, partial: true, ignoreLineage: $process->lineage_key);
        $rules['effective_from'] = ['required', 'date_format:Y-m-d'];
        unset($rules['code']);

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ProcessRules::attributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProcessRules::messages();
    }
}
