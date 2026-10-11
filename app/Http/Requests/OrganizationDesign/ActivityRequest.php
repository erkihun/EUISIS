<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Process;
use App\Models\ProcessActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An activity under a draft process: created under the process, updated by itself. */
class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->process()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $process = $this->process();
        $activity = $this->route('activity');
        $partial = $activity instanceof ProcessActivity;
        $required = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/',
                Rule::unique('process_activities', 'code')->where('process_id', $process->getKey())->ignore($partial ? $activity : null)],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'parent_activity_id' => ['nullable', 'uuid', Rule::exists('process_activities', 'id')->where('process_id', $process->getKey())],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function process(): Process
    {
        $activity = $this->route('activity');
        if ($activity instanceof ProcessActivity) {
            return $activity->process;
        }
        $process = $this->route('process');

        return $process instanceof Process ? $process : abort(404);
    }
}
