<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Enums\CardStatus;
use App\Models\IdCard;
use Illuminate\Foundation\Http\FormRequest;

class IssueCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        if (! $card instanceof IdCard) {
            return false;
        }

        // Lifecycle guard — enforced independently of Gate::before() super-admin bypass.
        if ($card->status !== CardStatus::Printed) {
            return false;
        }

        return $this->user()?->can('issue', $card) ?? false;
    }

    public function rules(): array
    {
        return [
            'issued_to' => ['nullable', 'string', 'max:255'],
            'received_by' => ['nullable', 'string', 'max:255'],
        ];
    }
}
