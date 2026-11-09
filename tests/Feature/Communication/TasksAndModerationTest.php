<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Communication\Application\Actions\CreateGroupAction;
use Modules\Communication\Application\Actions\SendMessageAction;
use Modules\Communication\Application\Actions\StartDirectConversationAction;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Contracts\Data\CommunicationRoles;
use Modules\Communication\Domain\Models\CommunicationReport;
use Modules\Communication\Domain\Models\CommunicationTask;
use Modules\Communication\Domain\Models\Message;
use Tests\Support\CommunicationScenario;

/*
 * Message → task, reporting, the moderator's narrow view of reported
 * content, and blocking.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
    $this->group = app(CreateGroupAction::class)->execute($this->s->alice, ['title' => 'Transfer desk', 'member_ids' => [$this->s->bob->id, $this->s->carol->id]]);
    $this->moderator = CommunicationScenario::employeeUser('Mulu Moderator', $this->s->orgA, $this->s->unitA2, CommunicationRoles::MODERATOR_PERMISSIONS, 'Comm Moderator');
});

afterEach(fn () => Carbon::setTestNow());

function ctSend(object $test, $sender, string $body, $conversation = null): Message
{
    return app(SendMessageAction::class)->execute($conversation ?? $test->group, $sender, ['body' => $body]);
}

it('turns a message into a task for a member, notes it in the conversation and notifies the assignee', function (): void {
    Notification::fake();
    $message = ctSend($this, $this->s->alice, 'Please prepare the October transfer list');

    $this->actingAs($this->s->alice)->postJson(route('communication.api.tasks.store', $message), ['assigned_to' => $this->s->dave->id])
        ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    $this->actingAs($this->s->alice)->postJson(route('communication.api.tasks.store', $message), [
        'assigned_to' => $this->s->bob->id, 'priority' => 'HIGH', 'due_at' => '2026-10-25 17:00:00',
    ])->assertCreated()->assertJsonPath('data.title', 'Please prepare the October transfer list')->assertJsonPath('data.source.message_id', $message->id);

    $task = CommunicationTask::query()->sole();
    expect($task->assigned_to)->toBe($this->s->bob->id)
        ->and(Message::query()->where('conversation_id', $this->group->id)->latest('id')->first()->metadata)->toMatchArray(['system_event' => 'task_created', 'subject_name' => 'Bob Bekele', 'task_id' => $task->id])
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationTaskCreated->value)->count())->toBe(1);
    Notification::assertSentTo($this->s->bob, CommunicationNotification::class, fn ($n) => $n->kind === 'task_assigned');
});

it('lets the assignee move the status, and only the creator change or cancel the task', function (): void {
    $message = ctSend($this, $this->s->alice, 'Check the payroll file');
    $this->actingAs($this->s->alice)->postJson(route('communication.api.tasks.store', $message), ['assigned_to' => $this->s->bob->id])->assertCreated();
    $task = CommunicationTask::query()->sole();

    $this->actingAs($this->s->bob)->patchJson(route('communication.api.tasks.update', $task), ['status' => 'COMPLETED'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    expect($task->fresh()->completed_at)->not->toBeNull();
    $this->actingAs($this->s->bob)->patchJson(route('communication.api.tasks.update', $task), ['title' => 'Something else'])->assertForbidden();
    $this->actingAs($this->s->bob)->patchJson(route('communication.api.tasks.update', $task), ['status' => 'CANCELLED'])->assertForbidden();
    $this->actingAs($this->s->carol)->patchJson(route('communication.api.tasks.update', $task), ['status' => 'TODO'])->assertNotFound();
    $this->actingAs($this->s->alice)->patchJson(route('communication.api.tasks.update', $task), ['assigned_to' => $this->s->carol->id, 'status' => 'TODO'])->assertOk()
        ->assertJsonPath('data.assignee.name', 'Carol Chala');

    $mine = collect($this->actingAs($this->s->carol)->getJson(route('communication.api.tasks.list'))->assertOk()->json('data'))->pluck('title');
    expect($mine->all())->toBe(['Check the payroll file']);
});

it('files one report per open case, without anyone else seeing it, and audits ids only', function (): void {
    $message = ctSend($this, $this->s->bob, 'Offensive remark');

    $this->actingAs($this->s->bob)->postJson(route('communication.api.reports.message', $message), ['category' => 'HARASSMENT'])->assertForbidden(); // own message
    $this->actingAs($this->s->dave)->postJson(route('communication.api.reports.message', $message), ['category' => 'HARASSMENT'])->assertNotFound();
    $first = $this->actingAs($this->s->alice)->postJson(route('communication.api.reports.message', $message), ['category' => 'HARASSMENT', 'details' => 'Third time this week'])->assertCreated()->json('id');
    $again = $this->actingAs($this->s->alice)->postJson(route('communication.api.reports.message', $message), ['category' => 'SPAM'])->assertCreated()->json('id');

    expect($again)->toBe($first)
        ->and(CommunicationReport::query()->sole()->reported_user_id)->toBe($this->s->bob->id)
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationReportCreated->value)->value('new_values'))->not->toContain('Offensive');
});

it('shows a moderator only the reported message and its neighbours, audited, and lets them remove it', function (): void {
    foreach (range(1, 6) as $i) {
        ctSend($this, $this->s->carol, "Earlier {$i}");
    }
    $reported = ctSend($this, $this->s->bob, 'Offensive remark');
    foreach (range(1, 6) as $i) {
        ctSend($this, $this->s->carol, "Later {$i}");
    }
    $this->actingAs($this->s->alice)->postJson(route('communication.api.reports.message', $reported), ['category' => 'HARASSMENT'])->assertCreated();
    $report = CommunicationReport::query()->sole();

    $this->actingAs($this->s->alice)->getJson(route('communication.api.reports.show', $report))->assertNotFound(); // not a moderator
    $view = $this->actingAs($this->moderator)->getJson(route('communication.api.reports.show', $report))->assertOk();
    $bodies = collect($view->json('messages'))->pluck('body')->all();
    expect($bodies)->toBe(['Earlier 4', 'Earlier 5', 'Earlier 6', 'Offensive remark', 'Later 1', 'Later 2', 'Later 3'])
        ->and($report->fresh()->status->value)->toBe('UNDER_REVIEW')
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationReportedContentAccessed->value)->count())->toBe(1);

    // The moderator is not a member: the conversation itself stays closed to them.
    $this->actingAs($this->moderator)->getJson(route('communication.api.conversations.messages', $this->group))->assertNotFound();

    $this->actingAs($this->moderator)->postJson(route('communication.api.reports.resolve', $report), ['status' => 'ACTIONED', 'note' => 'Removed: harassment', 'remove_message' => true])
        ->assertOk()->assertJsonPath('report.status', 'ACTIONED');
    expect(Message::withTrashed()->find($reported->id)->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationMessageModeratorDeleted->value)->count())->toBe(1);
    $this->actingAs($this->moderator)->postJson(route('communication.api.reports.resolve', $report), ['status' => 'DISMISSED', 'note' => 'Changed my mind'])->assertUnprocessable();
});

it('keeps moderators away from reports they filed or that are about them, and Super Admin out without the permission', function (): void {
    $message = ctSend($this, $this->s->bob, 'Something', app(StartDirectConversationAction::class)->execute($this->s->bob, $this->moderator));
    $this->actingAs($this->moderator)->postJson(route('communication.api.reports.message', $message), ['category' => 'SPAM'])->assertCreated();
    $report = CommunicationReport::query()->sole();

    $this->actingAs($this->moderator)->getJson(route('communication.api.reports.show', $report))->assertNotFound();
    expect($this->actingAs($this->moderator)->getJson(route('communication.api.reports.list'))->json('data'))->toBe([]);

    $root = CommunicationScenario::employeeUser('Root Admin', $this->s->orgA, $this->s->unitA1);
    $root->assignRole(CommunicationScenario::role('Super Admin', ['communication.access']));
    $this->actingAs($root)->getJson(route('communication.api.reports.show', $report))->assertNotFound();
    $this->actingAs($root)->get(route('communication.moderation.index'))->assertForbidden();
});

it('blocks quietly: no new direct conversation, no posting in the old one, no adding to groups; unblock restores it', function (): void {
    $direct = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    $this->actingAs($this->s->alice)->postJson(route('communication.api.blocks.store'), ['user_id' => $this->s->bob->id])->assertOk();

    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.store', $direct), ['body' => 'Hello?'])->assertForbidden();
    $this->actingAs($this->s->bob)->postJson(route('communication.api.groups.store'), ['title' => 'With Alice', 'member_ids' => [$this->s->alice->id]])
        ->assertUnprocessable();
    expect(collect($this->actingAs($this->s->alice)->getJson(route('communication.api.blocks.index'))->json('data'))->pluck('name')->all())->toBe(['Bob Bekele'])
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationUserBlocked->value)->count())->toBe(1);

    $this->actingAs($this->s->alice)->deleteJson(route('communication.api.blocks.destroy', $this->s->bob))->assertOk();
    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.store', $direct), ['body' => 'Hello again'])->assertCreated();
});
