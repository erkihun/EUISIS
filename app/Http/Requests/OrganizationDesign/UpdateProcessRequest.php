<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Process;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('process')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Process $process */
        $process = $this->route('process');

        return ProcessRules::rules($process->organization_id, partial: true, ignoreLineage: $process->lineage_key);
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
