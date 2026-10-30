<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueQueueTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.operate') ?? false;
    }

    public function rules(): array
    {
        return ['reception_counter_id' => ['nullable', 'uuid', 'exists:reception_counters,id'], 'priority' => ['sometimes', Rule::in(['normal', 'approved_priority'])]];
    }
}
