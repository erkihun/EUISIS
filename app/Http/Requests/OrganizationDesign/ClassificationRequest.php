<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\ServiceClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A single LOCAL/CPC classification entry, written by hand by a steward. */
class ClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $classification = $this->route('classification');

        return $classification instanceof ServiceClassification
            ? ($this->user()?->can('update', $classification) ?? false)
            : ($this->user()?->can('create', ServiceClassification::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $classification = $this->route('classification');
        $partial = $classification instanceof ServiceClassification;
        $required = $partial ? 'sometimes' : 'required';
        $scheme = $partial ? $classification->scheme : $this->string('scheme')->toString();
        $revision = $partial ? $classification->revision : $this->string('revision')->toString();

        return [
            'scheme' => [$partial ? 'prohibited' : 'required', Rule::in(ServiceClassification::SCHEMES)],
            'revision' => [$partial ? 'prohibited' : 'required', 'string', 'max:20'],
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/',
                Rule::unique('service_classifications', 'code')->where('scheme', $scheme)->where('revision', $revision)->ignore($classification)],
            'parent_id' => ['nullable', 'uuid', Rule::exists('service_classifications', 'id')->where('scheme', $scheme)->where('revision', $revision)],
            'level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
