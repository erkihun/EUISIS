<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use Illuminate\Foundation\Http\FormRequest;

class ReviewFieldWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:4000'], 'reason' => ['required', 'string', 'max:4000']];
    }
}
