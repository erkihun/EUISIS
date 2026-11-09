<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\ActionItemStatus;

/** Moves an action item along; closing it needs a completion note. */
final class UpdateActionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('actionItem'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ActionItemStatus::class)],
            'completion_note' => ['nullable', 'required_if:status,done', 'string', 'max:5000'],
        ];
    }
}
