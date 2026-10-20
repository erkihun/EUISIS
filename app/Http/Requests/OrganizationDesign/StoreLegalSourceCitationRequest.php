<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use Illuminate\Foundation\Http\FormRequest;

/** "This record rests on that source." The controller resolves and authorizes the subject. */
class StoreLegalSourceCitationRequest extends FormRequest
{
    public const SUBJECTS = ['mandate', 'hierarchy_version', 'baseline', 'methodology'];

    public function authorize(): bool
    {
        $source = $this->route('source');

        return $this->user()?->can('view', $source) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'in:'.implode(',', self::SUBJECTS)],
            'subject_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
