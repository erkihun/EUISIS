<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Enums\CardRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('id-cards.submitRequest')
            || $this->user()?->can('cards.manage')
            ? true
            : false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required_without:employee_ids', 'prohibits:employee_ids', 'uuid', 'exists:employees,id'],
            'employee_ids' => ['required_without:employee_id', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['required', 'uuid', 'distinct', 'exists:employees,id'],
            'request_type' => ['nullable', Rule::enum(CardRequestType::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'previous_card_id' => ['nullable', 'uuid', 'exists:id_cards,id'],
        ];
    }
}
