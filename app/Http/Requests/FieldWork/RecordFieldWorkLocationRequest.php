<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use App\Services\FieldWork\FieldWorkSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * One GPS capture from the browser's Geolocation API. Only the raw reading
 * is accepted: distance and in-area verdicts are computed on the server.
 */
class RecordFieldWorkLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'captured_at' => ['required', 'date'],
        ];
    }

    /** @return array{latitude: float, longitude: float, accuracy: ?float, captured_at: Carbon} */
    public function gps(): array
    {
        $accuracy = $this->validated('accuracy');

        return [
            'latitude' => round((float) $this->validated('latitude'), 7),
            'longitude' => round((float) $this->validated('longitude'), 7),
            'accuracy' => $accuracy === null ? null : round((float) $accuracy, 2),
            'captured_at' => app(FieldWorkSettings::class)->storage(Carbon::parse((string) $this->validated('captured_at'))),
        ];
    }
}
