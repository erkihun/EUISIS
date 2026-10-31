<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Events\CommunicationTaskAssigned;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Application\Services\CommunicationBroadcaster;
use Modules\Communication\Application\Services\MembershipWriter;
use Modules\Communication\Domain\Enums\TaskPriority;
use Modules\Communication\Domain\Enums\TaskStatus;
use Modules\Communication\Domain\Models\CommunicationTask;
use Modules\Communication\Domain\Models\ConversationMember;
use Modules\Communication\Domain\Models\Message;

/**
 * Turns a message into a task for a member of the same conversation (the
 * task stays linked to the message). The conversation gets a short note; the
 * assignee is notified. The task does not copy the message text unless the
 * creator writes it into the description.
 */
final class CreateTaskFromMessageAction
{
    public function __construct(
        private readonly MembershipWriter $writer,
        private readonly CommunicationBroadcaster $broadcaster,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @param array{title?: ?string, description?: ?string, assigned_to: int, priority?: ?string, due_at?: ?string} $data */
    public function execute(User $actor, Message $message, array $data): CommunicationTask
    {
        if (! Gate::forUser($actor)->allows('convertToTask', $message)) {
            throw new AuthorizationException;
        }
        $conversation = $message->conversation;
        $assignee = User::query()->where('status', 'active')->find((int) $data['assigned_to']);
        $member = $assignee !== null && ConversationMember::query()->where('conversation_id', $conversation->getKey())
            ->where('user_id', $assignee->getKey())->whereNull('left_at')->exists();
        if (! $member) {
            throw ValidationException::withMessages(['assigned_to' => __('communication.errors.assignee_not_member')]);
        }
        $due = filled($data['due_at'] ?? null) ? Carbon::parse((string) $data['due_at']) : null;
        if ($due !== null && $due->isPast()) {
            throw ValidationException::withMessages(['due_at' => __('communication.errors.due_in_past')]);
        }

        $task = (new CommunicationTask)->forceFill([
            'title' => filled($data['title'] ?? null) ? trim((string) $data['title']) : Str::limit(trim((string) preg_replace('/<[@!][^>]{1,30}>/', '', (string) $message->body)) ?: __('communication.tasks.untitled'), 120),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'source_message_id' => $message->getKey(),
            'conversation_id' => $conversation->getKey(),
            'assigned_to' => $assignee->getKey(),
            'created_by' => $actor->getKey(),
            'priority' => TaskPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TaskPriority::Normal,
            'status' => TaskStatus::Todo,
            'due_at' => $due,
        ]);
        $task->save();

        $note = $this->writer->note($conversation, $actor, 'task_created', $assignee->name);
        $note->forceFill(['metadata' => [...($note->metadata ?? []), 'task_id' => $task->getKey()]])->save();
        $this->audit->execute(AuditEventType::CommunicationTaskCreated, $actor, $task, $conversation->organization_id, null,
            ['assigned_to' => $assignee->getKey(), 'conversation_id' => $conversation->getKey(), 'source_message_id' => $message->getKey()]);
        if ((int) $assignee->getKey() !== (int) $actor->getKey()) {
            $assignee->notify(new CommunicationNotification('task_assigned', ['name' => $actor->name, 'task' => $task->title], '/communication/tasks?task='.$task->getKey()));
            $this->broadcaster->send(new CommunicationTaskAssigned($task->getKey(), (int) $assignee->getKey()));
        }

        return $task;
    }
}
