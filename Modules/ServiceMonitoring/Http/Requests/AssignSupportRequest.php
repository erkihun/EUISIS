<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** Sets a participant's support officers. */
final class AssignSupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Participant::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array', 'max:50'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
