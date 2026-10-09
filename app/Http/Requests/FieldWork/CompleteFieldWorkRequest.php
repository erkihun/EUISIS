<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use App\Services\FieldWork\FieldWorkSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class CompleteFieldWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'actual_return_date' => ['required', 'date_format:Y-m-d'],
            'actual_return_time' => ['required', 'date_format:H:i'],
            'completion_note' => ['required', 'string', 'min:5', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'follow_up_required' => ['required', 'boolean'],
            'follow_up_note' => ['nullable', 'required_if:follow_up_required,true,1', 'string', 'max:2000'],
        ];
    }

    /** @return array{actual_return_at: Carbon, completion_note: string, outcome: ?string, follow_up_required: bool, follow_up_note: ?string} */
    public function payload(): array
    {
        return [
            'actual_return_at' => app(FieldWorkSettings::class)->instant((string) $this->validated('actual_return_date'), (string) $this->validated('actual_return_time')),
            'completion_note' => (string) $this->validated('completion_note'),
            'outcome' => $this->validated('outcome'),
            'follow_up_required' => $this->boolean('follow_up_required'),
            'follow_up_note' => $this->validated('follow_up_note'),
        ];
    }
}
