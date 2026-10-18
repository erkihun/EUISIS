<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\Communication\Announcement;
use App\Models\Communication\AnnouncementReceipt;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Notifications\Communication\CommunicationNotification;
use App\Services\Communication\CommunicationInbox;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\Communication\CommunicationRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommunicationScenario;

/*
 * Announcements: scoped targeting, draft → publish/schedule → expire or
 * cancel, reader receipts and acknowledgements, reports and CSV export.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
    // HR drafts; the organization's admin publishes. Both are confined to the Civil Service Bureau.
    $this->hr = CommunicationScenario::employeeUser('Hana HR', $this->s->orgA, $this->s->unitA1, CommunicationRoles::HR_PERMISSIONS, 'Comm HR');
    $this->admin = CommunicationScenario::employeeUser('Abel Admin', $this->s->orgA, $this->s->unitA1, CommunicationRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS, 'Comm Org Admin');
    foreach ([$this->hr, $this->admin] as $user) {
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->s->orgA->id, 'scope_type' => 'self', 'is_active' => true]);
    }
    app(OrganizationScopeService::class)->clearCache();
});

afterEach(fn () => Carbon::setTestNow());

function caPayload(object $test, array $overrides = []): array
{
    return [
        'title' => 'New leave procedure', 'title_am' => 'አዲስ የፈቃድ አሠራር',
        'body' => 'From November, leave requests go through My Portal.', 'priority' => 'IMPORTANT',
        'acknowledgement_required' => false,
        'targets' => [['type' => 'ORGANIZATION', 'id' => $test->s->orgA->id]],
        ...$overrides,
    ];
}

function caCreate(object $test, array $overrides = [], ?User $as = null): Announcement
{
    $test->actingAs($as ?? $test->admin)->post(route('communication.announcements.store'), caPayload($test, $overrides))->assertRedirect();

    return Announcement::query()->latest('created_at')->latest('id')->firstOrFail();
}

function caFeed(object $test, User $user, string $filter = 'all'): array
{
    return collect($test->actingAs($user)->getJson(route('communication.api.announcements.feed', ['filter' => $filter]))->assertOk()->json('data'))->pluck('title')->all();
}

it('keeps targets inside the author\'s scope, and leaves publishing to those who may publish', function (): void {
    $this->actingAs($this->hr)->post(route('communication.announcements.store'), caPayload($this, ['targets' => [['type' => 'ORGANIZATION', 'id' => $this->s->orgB->id]]]))
        ->assertSessionHasErrors('targets');
    $this->actingAs($this->hr)->post(route('communication.announcements.store'), caPayload($this, ['targets' => [['type' => 'CITY']]]))
        ->assertSessionHasErrors('targets');

    $draft = caCreate($this, ['intent' => 'publish'], $this->hr);
    expect($draft->status->value)->toBe('DRAFT')
        ->and($draft->organization_id)->toBe($this->s->orgA->id)
        ->and(caFeed($this, $this->s->alice))->toBe([]);

    $this->actingAs($this->admin)->post(route('communication.announcements.publish', $draft))->assertRedirect();
    expect($draft->fresh()->status->value)->toBe('PUBLISHED')
        ->and($draft->fresh()->published_by)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationAnnouncementPublished->value)->count())->toBe(1);
});

it('reaches exactly its audience, notifies for important ones, and stays invisible to everyone else', function (): void {
    Notification::fake();
    $announcement = caCreate($this, ['intent' => 'publish']);

    expect(caFeed($this, $this->s->alice))->toBe(['New leave procedure'])
        ->and(caFeed($this, $this->s->dave))->toBe([])
        ->and($announcement->fresh()->target_count)->toBe(5); // alice, bob, carol, hr, admin
    $this->actingAs($this->s->dave)->get(route('communication.announcements.show', $announcement))->assertNotFound();
    Notification::assertSentTo($this->s->alice, CommunicationNotification::class, fn ($n) => $n->kind === 'announcement_important');
    Notification::assertNotSentTo($this->s->dave, CommunicationNotification::class);

    // Amharic readers get the Amharic title.
    app()->setLocale('am');
    expect(caFeed($this, $this->s->bob))->toBe(['አዲስ የፈቃድ አሠራር']);
});

it('targets a unit with its sub-units, a single employee, or a group the author belongs to', function (): void {
    $sub = OrganizationUnit::query()->create(['organization_id' => $this->s->orgA->id, 'parent_unit_id' => $this->s->unitA1->id, 'code' => 'COMM-A1-1', 'name_en' => 'Records', 'unit_type' => 'team', 'status' => 'active']);
    $erin = CommunicationScenario::employeeUser('Erin Eshete', $this->s->orgA, $sub);

    caCreate($this, ['title' => 'HR directorate only', 'targets' => [['type' => 'ORGANIZATION_UNIT', 'id' => $this->s->unitA1->id]], 'intent' => 'publish']);
    caCreate($this, ['title' => 'For Carol', 'targets' => [['type' => 'EMPLOYEE', 'id' => $this->s->carol->employee_id]], 'intent' => 'publish']);

    expect(caFeed($this, $erin))->toBe(['HR directorate only'])
        ->and(caFeed($this, $this->s->carol))->toBe(['For Carol'])
        ->and(caFeed($this, $this->s->bob))->toBe(['HR directorate only']);

    $group = app(\App\Actions\Communication\CreateGroupAction::class)->execute($this->s->alice, ['title' => 'Desk', 'member_ids' => [$this->s->bob->id]]);
    $this->actingAs($this->admin)->post(route('communication.announcements.store'), caPayload($this, ['targets' => [['type' => 'GROUP', 'id' => $group->id]]]))
        ->assertSessionHasErrors('targets');
});

it('schedules for later, publishes on time, and expires', function (): void {
    $this->actingAs($this->admin)->post(route('communication.announcements.store'), caPayload($this, [
        'intent' => 'schedule', 'publish_at' => '2026-10-18 14:00:00', 'expires_at' => '2026-10-20 09:00:00',
    ]))->assertRedirect();
    $announcement = Announcement::query()->sole();
    expect($announcement->status->value)->toBe('SCHEDULED')->and(caFeed($this, $this->s->alice))->toBe([]);

    Carbon::setTestNow('2026-10-18 14:01:00');
    $this->artisan('communication:publish-scheduled')->assertSuccessful();
    expect($announcement->fresh()->status->value)->toBe('PUBLISHED')->and(caFeed($this, $this->s->alice))->toBe(['New leave procedure']);

    Carbon::setTestNow('2026-10-20 09:05:00');
    expect(caFeed($this, $this->s->alice))->toBe([]);
    $this->artisan('communication:expire-announcements')->assertSuccessful();
    expect($announcement->fresh()->status->value)->toBe('EXPIRED')
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationAnnouncementExpired->value)->count())->toBe(1);
});

it('records views and acknowledgements once, counts them, and lists who is still pending', function (): void {
    $announcement = caCreate($this, ['acknowledgement_required' => true, 'intent' => 'publish']);
    $inbox = app(CommunicationInbox::class);
    expect($inbox->unreadAnnouncements($this->s->alice))->toBe(1)
        ->and(caFeed($this, $this->s->alice, 'acknowledge'))->toBe(['New leave procedure']);

    $this->actingAs($this->s->alice)->get(route('communication.announcements.show', $announcement))->assertOk();
    expect($inbox->unreadAnnouncements($this->s->alice))->toBe(0);
    $this->actingAs($this->s->alice)->post(route('communication.announcements.acknowledge', $announcement))->assertRedirect();
    $first = AnnouncementReceipt::query()->where('user_id', $this->s->alice->id)->value('acknowledged_at');
    Carbon::setTestNow('2026-10-18 10:00:00');
    $this->actingAs($this->s->alice)->post(route('communication.announcements.acknowledge', $announcement))->assertRedirect();

    expect(AnnouncementReceipt::query()->where('user_id', $this->s->alice->id)->count())->toBe(1)
        ->and((string) AnnouncementReceipt::query()->where('user_id', $this->s->alice->id)->value('acknowledged_at'))->toBe((string) $first)
        ->and(caFeed($this, $this->s->alice, 'acknowledge'))->toBe([]);

    $this->actingAs($this->s->bob)->get(route('communication.announcements.show', $announcement))->assertOk()
        ->assertInertia(fn ($page) => $page->where('announcement.can.acknowledge', true)->where('announcement.manage', null));
    // The admin is in the audience too: opening it is their view (Alice, Bob, Abel).
    $this->actingAs($this->admin)->get(route('communication.announcements.show', $announcement))->assertOk()
        ->assertInertia(fn ($page) => $page->where('announcement.manage.stats.viewed', 3)->where('announcement.manage.stats.acknowledged', 1));

    $pending = collect($this->actingAs($this->admin)->getJson(route('communication.api.announcements.recipients', [$announcement, 'status' => 'pending']))->assertOk()->json('data'))->pluck('name');
    expect($pending)->toContain('Bob Bekele')->not->toContain('Alice Abebe');
    $this->actingAs($this->s->bob)->getJson(route('communication.api.announcements.recipients', $announcement))->assertNotFound();
});

it('exports acknowledgements as CSV, audited, only for those allowed', function (): void {
    $announcement = caCreate($this, ['acknowledgement_required' => true, 'intent' => 'publish']);
    $this->actingAs($this->s->alice)->post(route('communication.announcements.acknowledge', $announcement));

    $this->actingAs($this->hr)->get(route('communication.announcements.export', $announcement))->assertNotFound();
    $csv = $this->actingAs($this->admin)->get(route('communication.announcements.export', $announcement))->assertOk()->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain('Alice Abebe')->toContain('Employee number')
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationAcknowledgementsExported->value)->count())->toBe(1);
});

it('withdraws with a reason, and never edits what is already out', function (): void {
    $announcement = caCreate($this, ['intent' => 'publish']);
    $this->actingAs($this->admin)->put(route('communication.announcements.update', $announcement), caPayload($this, ['title' => 'Changed']))
        ->assertSessionHasErrors('announcement');

    $this->actingAs($this->admin)->post(route('communication.announcements.cancel', $announcement), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post(route('communication.announcements.cancel', $announcement), ['reason' => 'Sent to the wrong bureau'])->assertRedirect();

    expect($announcement->fresh()->status->value)->toBe('CANCELLED')
        ->and(caFeed($this, $this->s->alice))->toBe([])
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationAnnouncementCancelled->value)->value('reason'))->toBe('Sent to the wrong bureau');
});

it('estimates the audience before publishing and lets authors search targets in scope only', function (): void {
    $this->actingAs($this->hr)->postJson(route('communication.api.announcements.audience'), ['targets' => [['type' => 'ORGANIZATION_UNIT', 'id' => $this->s->unitA1->id]]])
        ->assertOk()->assertJsonPath('count', 4); // alice, bob, hr, admin

    $names = collect($this->actingAs($this->hr)->getJson(route('communication.api.announcements.targets', ['type' => 'ORGANIZATION', 'q' => 'Bureau']))->json('data'))->pluck('name');
    expect($names->all())->toBe(['Civil Service Bureau']);
    $this->actingAs($this->s->alice)->getJson(route('communication.api.announcements.targets', ['type' => 'ORGANIZATION']))->assertForbidden();
});

it('lets readers download an announcement\'s files and nobody else', function (): void {
    Storage::fake('local');
    $announcement = caCreate($this);
    $upload = $this->actingAs($this->admin)->post(route('communication.api.announcements.attachments.store', $announcement),
        ['file' => UploadedFile::fake()->createWithContent('circular.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")], ['Accept' => 'application/json'])
        ->assertCreated();
    $this->actingAs($this->admin)->post(route('communication.announcements.publish', $announcement));
    $file = $upload->json('data.id');

    $this->actingAs($this->s->alice)->get(route('communication.announcements.files.download', $file))->assertOk();
    $this->actingAs($this->s->dave)->get(route('communication.announcements.files.download', $file))->assertNotFound();
});

it('edits a draft\'s audience and records the change', function (): void {
    $draft = caCreate($this, [], $this->hr);
    $this->actingAs($this->hr)->put(route('communication.announcements.update', $draft), caPayload($this, [
        'title' => 'Leave procedure (revised)', 'targets' => [['type' => 'ORGANIZATION_UNIT', 'id' => $this->s->unitA1->id]],
    ]))->assertRedirect();

    $this->actingAs($this->hr)->get(route('communication.announcements.edit', $draft))->assertOk()
        ->assertInertia(fn ($page) => $page->where('announcement.title', 'Leave procedure (revised)')
            ->where('announcement.targets.0.type', 'ORGANIZATION_UNIT')->where('announcement.targets.0.label', 'HR Directorate'));
    expect(AuditLog::query()->where('event_type', AuditEventType::CommunicationAnnouncementTargetsChanged->value)->count())->toBe(1);
});

it('treats the body as formatted text: required in words, sanitized, excerpted as plain text', function (): void {
    $this->actingAs($this->admin)->from('/')->post(route('communication.announcements.store'), caPayload($this, ['body' => '<p><br></p>']))
        ->assertSessionHasErrors('body');

    $announcement = caCreate($this, [
        'body' => '<h2>New procedure</h2><p>Apply through <a href="https://portal.addis.gov.et" target="_blank">My Portal</a>.<img src=x onerror=alert(1)></p><script>alert(1)</script>',
        'body_am' => '<p><br></p>',
    ]);

    expect($announcement->body)->toBe('<h2>New procedure</h2><p>Apply through <a href="https://portal.addis.gov.et" target="_blank" rel="noreferrer noopener">My Portal</a>.</p>')
        ->and($announcement->body_am)->toBeNull();

    $this->actingAs($this->admin)->post(route('communication.announcements.publish', $announcement))->assertRedirect();
    $feed = $this->actingAs($this->s->alice)->getJson(route('communication.api.announcements.feed'))->assertOk()->json('data');
    expect(collect($feed)->firstWhere('id', $announcement->id)['excerpt'])->toBe('New procedure Apply through My Portal.');
});
