<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A master task under an activity of a draft process. */
class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->process()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $task = $this->route('task');
        $partial = $task instanceof ProcessTask;
        $required = $partial ? 'sometimes' : 'required';
        $activityId = $partial ? $task->activity_id : $this->activity()->getKey();

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/',
                Rule::unique('process_tasks', 'code')->where('activity_id', $activityId)->ignore($partial ? $task : null)],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'required_output' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function activity(): ProcessActivity
    {
        $activity = $this->route('activity');

        return $activity instanceof ProcessActivity ? $activity : abort(404);
    }

    public function process(): Process
    {
        $task = $this->route('task');

        return $task instanceof ProcessTask ? $task->process : $this->activity()->process;
    }
}
