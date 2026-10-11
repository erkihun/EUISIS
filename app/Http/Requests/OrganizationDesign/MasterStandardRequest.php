<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\TaskQualitySource;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A work standard draft on a master task or an activity. Same fields and
 * rules as the position-task standard form in WorkStructureController, so
 * one standard means one thing everywhere.
 */
class MasterStandardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageStandards', $this->process()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'standard_measure' => ['nullable', 'string', 'max:500'],
            'bpr_reference' => ['nullable', 'string', 'max:255'],
            'planned_quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'quantity_unit' => ['nullable', 'string', 'max:64', 'required_with:planned_quantity'],
            'planned_time_minutes' => ['nullable', 'numeric', 'gt:0', 'max:1440'],
            'planned_quality' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'quality_unit' => ['nullable', 'string', 'max:64', 'required_with:planned_quality'],
            'quality_measure' => ['nullable', 'string', 'max:2000', 'required_with:planned_quality'],
            'quality_source' => ['required', Rule::in(TaskQualitySource::values())],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array<string, mixed> */
    public function standardData(): array
    {
        $data = $this->validated();
        if (blank($data['planned_quantity'] ?? null) && blank($data['planned_time_minutes'] ?? null) && blank($data['planned_quality'] ?? null)) {
            throw ValidationException::withMessages(['planned_quantity' => __('work-standards.dimension_required')]);
        }
        foreach (['planned_quantity', 'planned_time_minutes', 'planned_quality', 'quantity_unit', 'quality_unit', 'quality_measure', 'standard_measure', 'bpr_reference', 'effective_to'] as $field) {
            $data[$field] = blank($data[$field] ?? null) ? null : $data[$field];
        }

        return $data;
    }

    /** The master task or activity the standard measures. */
    public function subject(): ProcessTask|ProcessActivity
    {
        $standard = $this->route('standard');
        if ($standard instanceof PositionServiceTaskStandard) {
            $subject = $standard->subjectModel();

            return $subject instanceof ProcessTask || $subject instanceof ProcessActivity ? $subject : abort(404);
        }
        $task = $this->route('task');
        if ($task instanceof ProcessTask) {
            return $task;
        }
        $activity = $this->route('activity');

        return $activity instanceof ProcessActivity ? $activity : abort(404);
    }

    public function process(): Process
    {
        return $this->subject()->process;
    }
}
