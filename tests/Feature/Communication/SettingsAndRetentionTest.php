<?php

declare(strict_types=1);

use App\Actions\Communication\SendMessageAction;
use App\Actions\Communication\StartDirectConversationAction;
use App\Enums\AuditEventType;
use App\Jobs\Communication\DeliverAnnouncement;
use App\Models\AuditLog;
use App\Models\Communication\Announcement;
use App\Models\Communication\AnnouncementTarget;
use App\Models\Communication\CommunicationReport;
use App\Models\Communication\CommunicationUserSetting;
use App\Models\Communication\Message;
use App\Models\Communication\MessageAttachment;
use App\Notifications\Communication\CommunicationNotification;
use App\Services\Communication\CommunicationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CommunicationScenario;

/*
 * Communication settings (admin), personal preferences, and retention.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
    $this->admin = CommunicationScenario::employeeUser('Sara Settings', $this->s->orgA, $this->s->unitA1,
        ['communication.access', 'communication.settings.manage'], 'Comm Settings Admin');
});

afterEach(fn () => Carbon::setTestNow());

it('lets settings managers change the module settings, audited, even while the module is off', function (): void {
    $this->actingAs($this->s->alice)->get(route('communication.settings.index'))->assertForbidden();
    $this->actingAs($this->s->alice)->patch(route('communication.settings.update'), ['communication_enabled' => false])->assertForbidden();

    $this->actingAs($this->admin)->patch(route('communication.settings.update'), ['communication_enabled' => false, 'message_edit_window_minutes' => 30])
        ->assertRedirect();
    expect(app(CommunicationSettings::class)->enabled())->toBeFalse()
        ->and(app(CommunicationSettings::class)->editWindowMinutes())->toBe(30)
        ->and(AuditLog::query()->where('event_type', 'like', '%setting%')->count())->toBeGreaterThan(0);

    // Off for everyone else, still reachable to turn it back on.
    $this->actingAs($this->s->alice)->get(route('communication.index'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('communication.settings.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('fields')->has('metrics.realtime'));
    $this->actingAs($this->admin)->patch(route('communication.settings.update'), ['communication_enabled' => true])->assertRedirect();
    $this->actingAs($this->s->alice)->get(route('communication.index'))->assertOk();
});

it('rejects settings outside their limits', function (): void {
    $this->actingAs($this->admin)->patch(route('communication.settings.update'), ['message_edit_window_minutes' => 99999, 'break_glass_max_hours' => 500])
        ->assertSessionHasErrors(['message_edit_window_minutes', 'break_glass_max_hours']);
});

it('keeps personal preferences: no direct messages, hidden presence', function (): void {
    $this->actingAs($this->s->bob)->get(route('communication.preferences.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('preferences.allow_direct_messages', true));
    $this->actingAs($this->s->bob)->patch(route('communication.preferences.update'), ['allow_direct_messages' => false, 'show_online_status' => false])->assertRedirect();

    $this->actingAs($this->s->alice)->postJson(route('communication.api.conversations.store'), ['user_id' => $this->s->bob->id])->assertUnprocessable();
    $this->actingAs($this->s->bob)->postJson(route('communication.api.presence'))->assertOk();
    $bob = collect($this->actingAs($this->s->alice)->getJson(route('communication.api.directory.search', ['q' => 'Bob']))->json('data'))->firstWhere('name', 'Bob Bekele');
    expect($bob['online'])->toBeNull();
});

it('emails urgent announcements only to people who did not opt out', function (): void {
    Notification::fake();
    CommunicationScenario::setting('email_urgent_announcements', true);
    (new CommunicationUserSetting)->forceFill(['user_id' => $this->s->bob->id, 'email_announcements' => false])->save();
    $announcement = (new Announcement)->forceFill(['title' => 'Water outage', 'body' => 'Offices close at noon.', 'priority' => 'URGENT', 'status' => 'PUBLISHED', 'published_at' => now()]);
    $announcement->save();
    (new AnnouncementTarget)->forceFill(['announcement_id' => $announcement->id, 'target_type' => 'ORGANIZATION', 'target_id' => $this->s->orgA->id, 'created_at' => now()])->save();

    app()->call([new DeliverAnnouncement($announcement->id), 'handle']);

    Notification::assertSentTo($this->s->alice, CommunicationNotification::class, fn ($n) => in_array('mail', $n->channels, true));
    Notification::assertSentTo($this->s->bob, CommunicationNotification::class, fn ($n) => $n->channels === ['database']);
});

it('does nothing without a retention policy', function (): void {
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    $message = app(SendMessageAction::class)->execute($conversation, $this->s->alice, ['body' => 'Old']);
    $message->delete();
    Carbon::setTestNow('2028-01-01 09:00:00');

    $this->artisan('communication:prune')->assertSuccessful();
    expect(Message::withTrashed()->find($message->id))->not->toBeNull();
});

it('removes long-deleted messages and their files, keeps live and held ones, and clears abandoned uploads', function (): void {
    Storage::fake('local');
    CommunicationScenario::setting('retention_days', 30);
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    $held = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->carol);
    $live = app(SendMessageAction::class)->execute($conversation, $this->s->alice, ['body' => 'Still here']);
    $gone = app(SendMessageAction::class)->execute($conversation, $this->s->alice, ['body' => 'Deleted long ago']);
    $file = (new MessageAttachment)->forceFill(['conversation_id' => $conversation->id, 'message_id' => $gone->id, 'uploaded_by' => $this->s->alice->id, 'original_name' => 'a.pdf',
        'storage_name' => 'a.pdf', 'disk' => 'local', 'path' => 'communication/a.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 10, 'checksum' => Str::random(64), 'scan_status' => 'SKIPPED']);
    $file->save();
    Storage::disk('local')->put('communication/a.pdf', 'x');
    $onHold = app(SendMessageAction::class)->execute($held, $this->s->carol, ['body' => 'Under report']);
    (new CommunicationReport)->forceFill(['reporter_id' => $this->s->alice->id, 'subject_type' => 'MESSAGE', 'message_id' => $onHold->id, 'conversation_id' => $held->id,
        'category' => 'HARASSMENT', 'status' => 'OPEN'])->save();
    $orphan = (new MessageAttachment)->forceFill(['conversation_id' => $conversation->id, 'uploaded_by' => $this->s->alice->id, 'original_name' => 'b.pdf',
        'storage_name' => 'b.pdf', 'disk' => 'local', 'path' => 'communication/b.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 10, 'checksum' => Str::random(64), 'scan_status' => 'SKIPPED']);
    $orphan->save();
    $gone->delete();
    $onHold->delete();

    Carbon::setTestNow('2026-12-01 09:00:00');
    $this->artisan('communication:prune', ['--dry-run' => true])->expectsOutputToContain('messages: 1')->assertSuccessful();
    expect(Message::withTrashed()->find($gone->id))->not->toBeNull();

    $this->artisan('communication:prune')->assertSuccessful();
    expect(Message::withTrashed()->find($gone->id))->toBeNull()
        ->and(Message::query()->find($live->id))->not->toBeNull()
        ->and(Message::withTrashed()->find($onHold->id))->not->toBeNull()
        ->and(MessageAttachment::query()->find($file->id))->toBeNull()
        ->and(MessageAttachment::query()->find($orphan->id))->toBeNull()
        ->and(Storage::disk('local')->exists('communication/a.pdf'))->toBeFalse()
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationRetentionApplied->value)->count())->toBe(1);
});
