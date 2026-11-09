<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Enums\CardStatus;
use App\Models\IdCard;
use Illuminate\Foundation\Http\FormRequest;

class ReinstateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        // Status guard here too: Gate::before lets a Super Admin past the policy.
        if (! $card instanceof IdCard || $card->status !== CardStatus::Suspended) {
            return false;
        }

        return $this->user()?->can('reinstate', $card) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
