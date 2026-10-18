<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\CreateTaskFromMessageAction;
use App\Actions\Communication\UpdateTaskAction;
use App\Enums\Communication\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreTaskRequest;
use App\Models\Communication\CommunicationTask;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Tasks made from messages: the person's own list, creating one, and moving it along. */
class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Communication/Tasks', [
            'initial' => $this->page($request->user(), 'assigned', 'open'),
            'focus' => $request->string('task')->toString() ?: null,
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $view = $request->query('view') === 'created' ? 'created' : 'assigned';
        $status = in_array($request->query('status'), ['open', 'done', 'all'], true) ? (string) $request->query('status') : 'open';

        return response()->json($this->page($request->user(), $view, $status));
    }

    public function store(StoreTaskRequest $request, Message $message, CreateTaskFromMessageAction $create): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $message), 404);
        $task = $create->execute($request->user(), $message, $request->validated());

        return response()->json(['data' => $this->present($task->fresh(['assignee:id,name', 'creator:id,name', 'conversation.channel']), $request->user())], 201);
    }

    public function update(StoreTaskRequest $request, CommunicationTask $task, UpdateTaskAction $update): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $task), 404);
        $update->execute($request->user(), $task, $request->validated());

        return response()->json(['data' => $this->present($task->fresh(['assignee:id,name', 'creator:id,name', 'conversation.channel']), $request->user())]);
    }

    /** @return array{data: list<array<string, mixed>>} */
    private function page(User $user, string $view, string $status): array
    {
        $query = CommunicationTask::query()->with(['assignee:id,name', 'creator:id,name', 'conversation.channel'])
            ->where($view === 'created' ? 'created_by' : 'assigned_to', $user->getKey());
        match ($status) {
            'open' => $query->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value]),
            'done' => $query->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]),
            default => null,
        };
        // Open work: soonest due first (no date last); finished work: most recent first.
        $status === 'open'
            ? $query->orderByRaw('case when due_at is null then 1 else 0 end')->orderBy('due_at')->orderByDesc('created_at')
            : $query->orderByDesc('updated_at');

        return ['data' => $query->limit(200)->get()->map(fn (CommunicationTask $t) => $this->present($t, $user))->values()->all()];
    }

    /** @return array<string, mixed> */
    private function present(CommunicationTask $t, User $viewer): array
    {
        $locale = app()->getLocale();
        $gate = Gate::forUser($viewer);

        return [
            'id' => $t->getKey(),
            'title' => $t->title,
            'description' => $t->description,
            'priority' => $t->priority->value,
            'status' => $t->status->value,
            'due_at' => $t->due_at?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'assignee' => $t->assignee ? ['id' => $t->assignee->getKey(), 'name' => $t->assignee->name] : null,
            'creator' => $t->creator ? ['id' => $t->creator->getKey(), 'name' => $t->creator->name] : null,
            // A link back to the message only for people who can still read the conversation.
            'source' => $t->conversation && $gate->allows('view', $t->conversation) ? [
                'conversation_id' => $t->conversation_id,
                'message_id' => $t->source_message_id,
                'title' => $t->conversation->channel?->displayName($locale) ?? $t->conversation->title,
                'type' => $t->conversation->type->value,
            ] : null,
            'can' => ['updateStatus' => $gate->allows('updateStatus', $t), 'manage' => $gate->allows('manage', $t)],
        ];
    }
}
