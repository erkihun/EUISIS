<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use Illuminate\Foundation\Http\FormRequest;

class CaptureFieldWorkLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('field_work.location.capture_own') ?? false;
    }

    public function rules(): array
    {
        return ['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'accuracy_meters' => ['nullable', 'numeric', 'min:0'], 'altitude_meters' => ['nullable', 'numeric'], 'heading_degrees' => ['nullable', 'numeric', 'between:0,360'], 'speed_mps' => ['nullable', 'numeric', 'min:0'], 'idempotency_key' => ['required', 'uuid']];
    }
}
