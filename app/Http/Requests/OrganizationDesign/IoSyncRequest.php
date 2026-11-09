<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\WorkItemIoKind;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The typed I/O rows of a process, activity or task, replaced as a set. */
class IoSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();
        $process = $subject instanceof Process ? $subject : $subject->process;

        return $this->user()?->can('update', $process) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:200'],
            'items.*.kind' => ['required', Rule::in(WorkItemIoKind::values())],
            'items.*.label_en' => ['required', 'string', 'max:255'],
            'items.*.label_am' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.depends_on_activity_id' => ['nullable', 'uuid', 'exists:process_activities,id'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'items.*.kind' => __('organization-design.fields.kind'),
            'items.*.label_en' => __('organization-design.fields.label_en'),
            'items.*.label_am' => __('organization-design.fields.label_am'),
            'items.*.depends_on_activity_id' => __('organization-design.fields.depends_on'),
        ];
    }

    public function subject(): Process|ProcessActivity|ProcessTask
    {
        $id = (string) $this->route('subject');
        $model = match ((string) $this->route('subjectType')) {
            'process' => Process::query()->find($id),
            'activity' => ProcessActivity::query()->find($id),
            'task' => ProcessTask::query()->find($id),
            default => null,
        };

        return $model ?? abort(404);
    }
}
