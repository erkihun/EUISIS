<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\VisitStatus;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** Plans or updates a monitoring visit (D22). */
final class SaveVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visit = $this->route('visit');
        if ($visit instanceof MonitoringVisit) {
            return $this->user()->can('update', $visit);
        }
        $participant = Participant::query()->find($this->input('participant_id'));

        return $participant !== null && $this->user()->can('manageVisits', $participant);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'participant_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid'],
            'period_id' => ['nullable', 'uuid', 'exists:service_monitoring_periods,id'],
            'visited_on' => ['required', 'date_format:Y-m-d'],
            'purpose' => ['required', 'string', 'min:5', 'max:5000'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'status' => [$this->isMethod('post') ? 'prohibited' : 'required', Rule::enum(VisitStatus::class)],
            'member_ids' => ['sometimes', 'array', 'max:20'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
