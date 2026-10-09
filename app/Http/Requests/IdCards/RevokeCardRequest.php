<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Enums\CardStatus;
use App\Models\IdCard;
use Illuminate\Foundation\Http\FormRequest;

class RevokeCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        if (! $card instanceof IdCard) {
            return false;
        }

        $irrevocable = [CardStatus::Revoked, CardStatus::Replaced, CardStatus::Expired];
        if (in_array($card->status, $irrevocable, true)) {
            return false;
        }

        return $this->user()?->can('revoke', $card) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
