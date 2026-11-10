<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Forwarding a letter inside the institution. The addressee's institution is checked by RouteLetter. */
final class RouteLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_user_id' => ['nullable', 'integer'],
            'to_unit_id' => ['nullable', 'uuid'],
            'instruction' => ['required', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date'],
        ];
    }
}
