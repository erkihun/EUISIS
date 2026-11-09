<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** A question to the support officers (D22). */
final class OpenClarificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $participant = Participant::query()->find($this->input('participant_id'));

        return $participant !== null && $this->user()->can('openClarification', $participant);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'uuid'],
            'submission_id' => ['nullable', 'uuid'],
            'period_id' => ['nullable', 'uuid', 'exists:service_monitoring_periods,id'],
            'section' => ['nullable', 'integer', 'between:1,4'],
            'subject' => ['required', 'string', 'min:3', 'max:255'],
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ];
    }
}
