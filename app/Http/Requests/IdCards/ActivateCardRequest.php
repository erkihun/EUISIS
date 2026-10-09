<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Enums\CardStatus;
use App\Models\IdCard;
use Illuminate\Foundation\Http\FormRequest;

class ActivateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        if (! $card instanceof IdCard) {
            return false;
        }

        if ($card->status !== CardStatus::Issued) {
            return false;
        }

        return $this->user()?->can('activate', $card) ?? false;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
