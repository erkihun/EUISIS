<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\ResetPolicy;

/** One institution's (or the platform's) numbering rule for a direction. */
final class SaveNumberingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $owner = $this->input('organization_id');

        return app(LettersAccess::class)->canManageNumbering($user, is_string($owner) && $owner !== '' ? $owner : null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'direction' => ['required', Rule::in(LetterDirection::values())],
            'language' => ['nullable', Rule::in(['am', 'en'])],
            'language_code' => ['nullable', 'string', 'max:12'],
            'pattern' => ['required', 'string', 'max:120'],
            'reset_policy' => ['nullable', Rule::in(ResetPolicy::values())],
            'padding' => ['nullable', 'integer', 'min:1', 'max:8'],
            'direction_code' => ['nullable', 'string', 'max:12'],
        ];
    }
}
