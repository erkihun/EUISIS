<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Models\CardRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveCardRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cardRequest = $this->route('cardRequest');

        return $cardRequest instanceof CardRequest
            ? $this->user()?->can('approve', $cardRequest) ?? false
            : ($this->user()?->can('id-cards.approveRequest') || $this->user()?->can('cards.manage')) ?? false;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
