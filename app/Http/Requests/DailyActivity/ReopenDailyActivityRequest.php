<?php

declare(strict_types=1);

namespace App\Http\Requests\DailyActivity;

use Illuminate\Foundation\Http\FormRequest;

/** Reopening an approved log always needs a recorded reason. */
class ReopenDailyActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
