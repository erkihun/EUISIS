<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Enums\CardStatus;
use App\Models\IdCard;
use Illuminate\Foundation\Http\FormRequest;

class ReportDamagedCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        if (! $card instanceof IdCard) {
            return false;
        }

        if (! in_array($card->status, [CardStatus::Active, CardStatus::Issued], true)) {
            return false;
        }

        return $this->user()?->can('reportDamaged', $card) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
