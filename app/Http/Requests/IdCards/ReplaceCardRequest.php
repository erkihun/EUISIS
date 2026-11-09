<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Enums\CardStatus;
use App\Models\IdCard;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        if (! $card instanceof IdCard) {
            return false;
        }

        $replaceable = [CardStatus::Lost, CardStatus::Damaged, CardStatus::Expired, CardStatus::Active];
        if (! in_array($card->status, $replaceable, true)) {
            return false;
        }

        return $this->user()?->can('replace', $card) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
