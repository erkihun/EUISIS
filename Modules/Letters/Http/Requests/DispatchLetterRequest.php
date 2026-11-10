<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Letters\Domain\Enums\DispatchMode;

/** The dispatch register's entry: how the letter leaves and under which tracking reference. Authorization is the controller's policy check. */
final class DispatchLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in(DispatchMode::values())],
            'reference' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160', 'required_if:mode,email'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
