<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Domain\Enums\TaskPriority;
use Modules\Communication\Domain\Enums\TaskStatus;
use Modules\Communication\Domain\Models\CommunicationTask;
use Modules\Communication\Domain\Models\ConversationMember;

/**
 * The assignee and the creator move a task's status; the creator (or a task
 * manager on it) changes its details, reassigns it within the conversation
 * or cancels it.
 */
final class UpdateTaskAction
{
    /** @param array{status?: string, title?: string, description?: ?string, priority?: string, due_at?: ?string, assigned_to?: int} $data */
    public function execute(User $actor, CommunicationTask $task, array $data): CommunicationTask
    {
        $gate = Gate::forUser($actor);
        $details = array_intersect_key($data, array_flip(['title', 'description', 'priority', 'due_at', 'assigned_to']));
        if (isset($data['status']) && ! $gate->allows('updateStatus', $task)) {
            throw new AuthorizationException;
        }
        if (($details !== [] || ($data['status'] ?? null) === TaskStatus::Cancelled->value) && ! $gate->allows('manage', $task)) {
            throw new AuthorizationException;
        }

        if (isset($data['status'])) {
            $status = TaskStatus::from($data['status']);
            $task->forceFill(['status' => $status, 'completed_at' => $status === TaskStatus::Completed ? ($task->completed_at ?? now()) : null]);
        }
        if (array_key_exists('title', $details)) {
            $task->title = trim((string) $details['title']);
        }
        if (array_key_exists('description', $details)) {
            $task->description = filled($details['description']) ? trim((string) $details['description']) : null;
        }
        if (isset($details['priority'])) {
            $task->priority = TaskPriority::from($details['priority']);
        }
        if (array_key_exists('due_at', $details)) {
            $task->due_at = filled($details['due_at']) ? Carbon::parse((string) $details['due_at']) : null;
        }
        $reassigned = null;
        if (isset($details['assigned_to']) && (int) $details['assigned_to'] !== (int) $task->assigned_to) {
            $member = $task->conversation_id === null || ConversationMember::query()->where('conversation_id', $task->conversation_id)
                ->where('user_id', (int) $details['assigned_to'])->whereNull('left_at')->exists();
            $reassigned = User::query()->where('status', 'active')->find((int) $details['assigned_to']);
            if (! $member || $reassigned === null) {
                throw ValidationException::withMessages(['assigned_to' => __('communication.errors.assignee_not_member')]);
            }
            $task->assigned_to = $reassigned->getKey();
        }
        $task->save();

        if ($reassigned !== null && (int) $reassigned->getKey() !== (int) $actor->getKey()) {
            $reassigned->notify(new CommunicationNotification('task_assigned', ['name' => $actor->name, 'task' => $task->title], '/communication/tasks?task='.$task->getKey()));
        }

        return $task;
    }
}
