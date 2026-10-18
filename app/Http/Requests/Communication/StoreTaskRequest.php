<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use App\Enums\Communication\TaskPriority;
use App\Enums\Communication\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A task made from a message, or changes to one (authorization: the task actions). */
class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'assigned_to' => [$creating ? 'required' : 'sometimes', 'integer'],
            'title' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', 'nullable', Rule::in(TaskPriority::values())],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::in(TaskStatus::values())],
        ];
    }
}
