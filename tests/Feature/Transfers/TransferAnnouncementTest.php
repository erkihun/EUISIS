<?php

declare(strict_types=1);

use App\Actions\Transfers\CancelTransferAnnouncementAction;
use App\Actions\Transfers\CloseTransferAnnouncementAction;
use App\Actions\Transfers\PublishTransferAnnouncementAction;
use App\Actions\Transfers\UpdateTransferAnnouncementAction;
use App\Enums\TransferAnnouncementStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\TransferAnnouncement;
use App\Models\User;
use Spatie\Permission\Models\Permission;

// ── Helpers ──────────────────────────────────────────────────────────────────

function taOrg(string $code): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'TA-DEPT'], ['name_en' => 'TA Department']);

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => 'TA Org '.$code,
        'status' => 'active',
    ]);
}

function taPos(string $orgId): Position
{
    static $n = 0;
    $n++;

    return Position::query()->create([
        'organization_id' => $orgId,
        'title_en' => 'TA Position '.$n,
        'job_position_code' => 'TA-POS-'.$n,
        'is_active' => true,
    ]);
}

function taEstablishment(string $orgId, string $posId, int $slots = 2): PositionEstablishment
{
    static $m = 0;
    $m++;

    return PositionEstablishment::query()->create([
        'organization_id' => $orgId,
        'position_id' => $posId,
        'establishment_number' => 'EST-TA-'.$m,
        'approved_slots' => $slots,
        'status' => 'approved',
        'effective_from' => now()->subYear()->toDateString(),
    ]);
}

function taDraftAnnouncement(string $orgId, string $posId): TransferAnnouncement
{
    return TransferAnnouncement::query()->create([
        'organization_id' => $orgId,
        'position_id' => $posId,
        'number_of_vacancies' => 2,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);
}

function taUser(string ...$perms): User
{
    foreach ($perms as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    $user = User::factory()->create();
    foreach ($perms as $perm) {
        $user->givePermissionTo($perm);
    }

    return $user;
}

// ── Index page ────────────────────────────────────────────────────────────────

test('index page loads for authorized user', function (): void {
    Permission::findOrCreate('transfers.announcements.view', 'web');
    $user = taUser('transfers.announcements.view');

    $this->actingAs($user)
        ->get(route('transfer-announcements.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Transfers/Announcements/Index'));
});

test('index page returns 403 for user without permission', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('transfer-announcements.index'))
        ->assertForbidden();
});

test('index page supports status filter', function (): void {
    $user = taUser('transfers.announcements.view');

    $this->actingAs($user)
        ->get(route('transfer-announcements.index', ['status' => 'draft']))
        ->assertOk();
});

// ── Create page ───────────────────────────────────────────────────────────────

test('create page loads for authorized user', function (): void {
    $user = taUser('transfers.announcements.view', 'transfers.announcements.create');

    $this->actingAs($user)
        ->get(route('transfer-announcements.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Transfers/Announcements/Create'));
});

test('create page returns 403 for user without create permission', function (): void {
    $user = taUser('transfers.announcements.view');

    $this->actingAs($user)
        ->get(route('transfer-announcements.create'))
        ->assertForbidden();
});

// ── Store ─────────────────────────────────────────────────────────────────────

test('announcement can be created as draft', function (): void {
    $org = taOrg('STORE-01');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.create');

    $this->actingAs($user)->post(route('transfer-announcements.store'), [
        'number_of_vacancies' => 2,
        'positions' => [[
            'organization_id' => $org->id,
            'position_id' => $pos->id,
            'grade_level' => 'Grade 5',
            'salary_min' => null,
            'salary_max' => null,
            'vacancy_count' => 2,
        ]],
        'eligibility_rules' => ['Min 2 years experience'],
        'required_documents' => ['ID Card', 'CV'],
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
    ])->assertRedirect();

    $this->assertDatabaseHas('transfer_announcements', [
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'status' => 'draft',
    ]);
});

test('store fails with invalid closing date', function (): void {
    $org = taOrg('STORE-BAD');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.create');

    $this->actingAs($user)->post(route('transfer-announcements.store'), [
        'number_of_vacancies' => 1,
        'positions' => [[
            'organization_id' => $org->id,
            'position_id' => $pos->id,
            'vacancy_count' => 1,
        ]],
        'opening_date' => now()->addDays(10)->toDateString(),
        'closing_date' => now()->addDay()->toDateString(), // before opening
    ])->assertSessionHasErrors('closing_date');
});

// ── Edit page ─────────────────────────────────────────────────────────────────

test('edit page loads for draft announcement', function (): void {
    $org = taOrg('EDIT-01');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.update');
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)
        ->get(route('transfer-announcements.edit', $ann))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Transfers/Announcements/Edit'));
});

test('edit page returns 403 for published announcement', function (): void {
    $org = taOrg('EDIT-PUB');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.update');

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => User::factory()->create()->id,
        'published_by' => User::factory()->create()->id,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('transfer-announcements.edit', $ann))
        ->assertForbidden();
});

// ── Update ────────────────────────────────────────────────────────────────────

test('draft announcement can be updated', function (): void {
    $org = taOrg('UPD-01');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.update');
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)->patch(route('transfer-announcements.update', $ann), [
        'positions' => [[
            'organization_id' => $org->id,
            'position_id' => $pos->id,
            'grade_level' => 'Grade 6',
            'salary_min' => null,
            'salary_max' => null,
            'vacancy_count' => 3,
        ]],
        'opening_date' => now()->addDays(2)->toDateString(),
        'closing_date' => now()->addDays(45)->toDateString(),
    ])->assertRedirect(route('transfer-announcements.show', $ann));

    $ann->refresh();
    expect($ann->number_of_vacancies)->toBe(3);
});

test('UpdateTransferAnnouncementAction throws for non-draft', function (): void {
    $org = taOrg('UPD-PUB');
    $pos = taPos($org->id);
    $user = User::factory()->create();

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(10)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => $user->id,
        'published_by' => $user->id,
        'published_at' => now(),
    ]);

    $action = app(UpdateTransferAnnouncementAction::class);

    expect(fn () => $action->execute($ann, [], $user))->toThrow(DomainException::class);
});

// ── Publish ───────────────────────────────────────────────────────────────────

test('draft announcement can be published via route', function (): void {
    $org = taOrg('PUB-RT');
    $pos = taPos($org->id);
    taEstablishment($org->id, $pos->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.publish');
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)
        ->post(route('transfer-announcements.publish', $ann))
        ->assertRedirect(route('transfer-announcements.index'));

    $ann->refresh();
    expect($ann->status)->toBe(TransferAnnouncementStatus::Published);
    expect($ann->published_by)->toBe($user->id);
});

test('published announcement cannot be published again', function (): void {
    $org = taOrg('PUB-DUP');
    $pos = taPos($org->id);
    taEstablishment($org->id, $pos->id);
    $user = User::factory()->create();

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(10)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => $user->id,
        'published_by' => $user->id,
        'published_at' => now(),
    ]);

    $action = app(PublishTransferAnnouncementAction::class);

    expect(fn () => $action->execute($ann, $user))->toThrow(DomainException::class);
});

// ── Close ─────────────────────────────────────────────────────────────────────

test('published announcement can be closed', function (): void {
    $org = taOrg('CLOSE-01');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.close');

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => User::factory()->create()->id,
        'published_by' => User::factory()->create()->id,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('transfer-announcements.close', $ann))
        ->assertRedirect(route('transfer-announcements.index'));

    $ann->refresh();
    expect($ann->status)->toBe(TransferAnnouncementStatus::Closed);
});

test('CloseTransferAnnouncementAction throws for draft', function (): void {
    $org = taOrg('CLOSE-DRAFT');
    $pos = taPos($org->id);
    $user = User::factory()->create();
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $action = app(CloseTransferAnnouncementAction::class);

    expect(fn () => $action->execute($ann, $user))->toThrow(DomainException::class);
});

test('closed announcement cannot be edited', function (): void {
    $org = taOrg('CLOSED-EDIT');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.update');

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDays(10)->toDateString(),
        'closing_date' => now()->subDay()->toDateString(),
        'status' => TransferAnnouncementStatus::Closed,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->actingAs($user)
        ->get(route('transfer-announcements.edit', $ann))
        ->assertForbidden();
});

// ── Cancel ────────────────────────────────────────────────────────────────────

test('draft announcement can be cancelled via route', function (): void {
    $org = taOrg('CAN-RT');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.close');
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)
        ->post(route('transfer-announcements.cancel', $ann))
        ->assertRedirect(route('transfer-announcements.index'));

    $ann->refresh();
    expect($ann->status)->toBe(TransferAnnouncementStatus::Cancelled);
});

test('published announcement can be cancelled via route', function (): void {
    $org = taOrg('CAN-PUB-RT');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.close');

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(10)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => User::factory()->create()->id,
        'published_by' => User::factory()->create()->id,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('transfer-announcements.cancel', $ann))
        ->assertRedirect(route('transfer-announcements.index'));

    $ann->refresh();
    expect($ann->status)->toBe(TransferAnnouncementStatus::Cancelled);
});

test('closed announcement cannot be cancelled', function (): void {
    $org = taOrg('CAN-CLOSED');
    $pos = taPos($org->id);
    $user = User::factory()->create();

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDays(10)->toDateString(),
        'closing_date' => now()->subDay()->toDateString(),
        'status' => TransferAnnouncementStatus::Closed,
        'created_by' => $user->id,
    ]);

    $action = app(CancelTransferAnnouncementAction::class);

    expect(fn () => $action->execute($ann, $user))->toThrow(DomainException::class);
});

test('cancelled announcement cannot be cancelled again', function (): void {
    $org = taOrg('CAN-AGAIN');
    $pos = taPos($org->id);
    $user = User::factory()->create();

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(5)->toDateString(),
        'status' => TransferAnnouncementStatus::Cancelled,
        'created_by' => $user->id,
    ]);

    $action = app(CancelTransferAnnouncementAction::class);

    expect(fn () => $action->execute($ann, $user))->toThrow(DomainException::class);
});

// ── Authorization ─────────────────────────────────────────────────────────────

test('cancel route returns 403 for user without permission', function (): void {
    $org = taOrg('AUTHZ-CAN');
    $pos = taPos($org->id);
    $user = User::factory()->create(); // no permissions
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)
        ->post(route('transfer-announcements.cancel', $ann))
        ->assertForbidden();
});

test('close route returns error flash for draft announcement', function (): void {
    Permission::findOrCreate('transfers.announcements.update', 'web');
    $org = taOrg('CLOSE-DRAFT-RT');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.close');
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)
        ->post(route('transfer-announcements.close', $ann))
        ->assertRedirect();

    $ann->refresh();
    expect($ann->status)->toBe(TransferAnnouncementStatus::Draft);
});

// ── Show page ─────────────────────────────────────────────────────────────────

test('show page loads with correct can permissions', function (): void {
    $org = taOrg('SHOW-01');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view');
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)
        ->get(route('transfer-announcements.show', $ann))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transfers/Announcements/Show')
            ->has('can')
        );
});

// ── Destroy ───────────────────────────────────────────────────────────────────

test('draft announcement can be deleted', function (): void {
    $org = taOrg('DEL-01');
    $pos = taPos($org->id);
    $user = taUser('transfers.announcements.view', 'transfers.announcements.update');
    $ann = taDraftAnnouncement($org->id, $pos->id);

    $this->actingAs($user)
        ->delete(route('transfer-announcements.destroy', $ann))
        ->assertRedirect(route('transfer-announcements.index'));

    $this->assertSoftDeleted('transfer_announcements', ['id' => $ann->id]);
});
