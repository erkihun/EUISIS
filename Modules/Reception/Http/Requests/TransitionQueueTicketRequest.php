<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionQueueTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.operate') ?? false;
    }

    public function rules(): array
    {
        return ['transition' => ['required', Rule::in(['call', 'recall', 'start', 'complete', 'transfer', 'cancel', 'no_show'])], 'reception_counter_id' => ['nullable', 'uuid', 'exists:reception_counters,id']];
    }
}
