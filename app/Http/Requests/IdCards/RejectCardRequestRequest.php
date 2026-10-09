<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Models\CardRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectCardRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cardRequest = $this->route('cardRequest');

        return $cardRequest instanceof CardRequest
            ? $this->user()?->can('reject', $cardRequest) ?? false
            : ($this->user()?->can('id-cards.rejectRequest') || $this->user()?->can('cards.manage')) ?? false;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
