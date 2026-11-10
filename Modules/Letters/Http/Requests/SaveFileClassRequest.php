<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\LetterFileClass;

/** One entry of a file classification scheme (the institution's, or the platform's when organization_id is empty). */
final class SaveFileClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $class = $this->route('fileClass');
        $owner = $class instanceof LetterFileClass ? $class->organization_id : $this->input('organization_id');

        return app(LettersAccess::class)->canManageStandards($user, is_string($owner) && $owner !== '' ? $owner : null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'parent_id' => ['nullable', 'uuid', Rule::exists('letter_file_classes', 'id')],
            'code' => ['required', 'string', 'max:40'],
            'title_am' => ['nullable', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
