<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** Adds or changes a registry row (D7). Status fields are never taken from the request. */
final class SaveParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Participant::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid', 'exists:organizations,id'],
            'level' => ['required', Rule::enum(ReportingLevel::class)],
            'parent_participant_id' => ['nullable', 'uuid', 'exists:service_monitoring_participants,id'],
            'active_from' => ['required', 'date_format:Y-m-d'],
            'active_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:active_from'],
        ];
    }
}
