<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PositionEstablishment;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePositionEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $establishment = $this->route('position_establishment');

        return $establishment instanceof PositionEstablishment
            && ($this->user()?->can('update', $establishment) ?? false);
    }

    public function rules(): array
    {
        return [
            'approved_slots' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'approval_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
