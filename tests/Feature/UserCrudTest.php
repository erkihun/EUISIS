<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'users.viewAny',
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'users.archive',
        'users.restore',
        'users.assignRoles',
        'users.deactivate',
        'users.updateProfilePhoto',
        'users.viewSensitive',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('User Manager', 'web')->syncPermissions([
        'users.viewAny',
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'users.archive',
        'users.restore',
        'users.assignRoles',
        'users.deactivate',
        'users.updateProfilePhoto',
        'users.viewSensitive',
    ]);

    Role::findOrCreate('User Viewer', 'web')->syncPermissions([
        'users.viewAny',
    ]);
});

function userMgmtAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('User Manager');

    return $user;
}

function userMgmtViewer(): User
{
    $user = User::factory()->create();
    $user->assignRole('User Viewer');

    return $user;
}

function makeTargetUser(): User
{
    return User::factory()->create(['status' => 'active']);
}

// ── Index ──────────────────────────────────────────────────────────────────

test('guests are redirected from the users index', function (): void {
    $this->get(route('users.index'))
        ->assertRedirect(route('login'));
});

test('users with viewAny permission can view the users index', function (): void {
    $this->actingAs(userMgmtViewer())
        ->get(route('users.index'))
        ->assertOk();
});

test('users without viewAny permission cannot view the users index', function (): void {
    $noRoleUser = User::factory()->create();

    $this->actingAs($noRoleUser)
        ->get(route('users.index'))
        ->assertForbidden();
});

// ── Create page ────────────────────────────────────────────────────────────

test('users without create permission cannot access the create page', function (): void {
    $this->actingAs(userMgmtViewer())
        ->get(route('users.create'))
        ->assertForbidden();
});

test('users with create permission can access the create page', function (): void {
    $this->actingAs(userMgmtAdmin())
        ->get(route('users.create'))
        ->assertOk();
});

// ── Store ──────────────────────────────────────────────────────────────────

test('users without create permission cannot store a user', function (): void {
    $this->actingAs(userMgmtViewer())
        ->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
        ])
        ->assertForbidden();
});

test('users with create permission can store a user', function (): void {
    $this->actingAs(userMgmtAdmin())
        ->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
        ])
        ->assertRedirect(route('users.index'));

    $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
});

test('users with create permission can store profile fields and photo', function (): void {
    Storage::fake('public');
    Role::findOrCreate('Operator', 'web');

    $this->actingAs(userMgmtAdmin())
        ->post(route('users.store'), [
            'name' => 'Profile User',
            'email' => 'profile-user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'national_id' => 'AA-2026-001',
            'phone_number' => '+251911111111',
            'gender' => 'other',
            'roles' => ['Operator'],
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    $created = User::query()->where('email', 'profile-user@example.com')->firstOrFail();

    expect($created->national_id)->toBe('AA-2026-001')
        ->and($created->phone_number)->toBe('+251911111111')
        ->and($created->gender)->toBe('other')
        ->and($created->hasRole('Operator'))->toBeTrue()
        ->and($created->profile_photo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($created->profile_photo_path);
    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $created->id,
        'event_type' => AuditEventType::UserCreated->value,
    ]);
});

test('store user rejects invalid gender', function (): void {
    $this->actingAs(userMgmtAdmin())
        ->post(route('users.store'), [
            'name' => 'Invalid Gender',
            'email' => 'invalid-gender@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'gender' => 'unknown',
        ])
        ->assertSessionHasErrors('gender');
});

// ── Edit page ──────────────────────────────────────────────────────────────

test('users without update permission cannot access the edit page', function (): void {
    $target = makeTargetUser();

    $this->actingAs(userMgmtViewer())
        ->get(route('users.edit', $target))
        ->assertForbidden();
});

test('users with update permission can access the edit page', function (): void {
    $target = makeTargetUser();

    $this->actingAs(userMgmtAdmin())
        ->get(route('users.edit', $target))
        ->assertOk();
});

// ── Update ─────────────────────────────────────────────────────────────────

test('users without update permission cannot update a user', function (): void {
    $target = makeTargetUser();

    $this->actingAs(userMgmtViewer())
        ->patch(route('users.update', $target), [
            'name' => 'Changed Name',
            'email' => $target->email,
            'status' => 'active',
        ])
        ->assertForbidden();
});

test('users with update permission can update a user', function (): void {
    $target = makeTargetUser();

    $this->actingAs(userMgmtAdmin())
        ->patch(route('users.update', $target), [
            'name' => 'Updated Name',
            'email' => $target->email,
            'status' => 'active',
        ])
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->name)->toBe('Updated Name');
});

test('users with update permission can update profile fields photo and roles', function (): void {
    Storage::fake('public');
    $target = makeTargetUser();
    Role::findOrCreate('Supervisor', 'web');

    $this->actingAs(userMgmtAdmin())
        ->patch(route('users.update', $target), [
            'name' => 'Updated Profile User',
            'email' => $target->email,
            'status' => 'active',
            'national_id' => 'AA-2026-UPDATED',
            'phone_number' => '+251922222222',
            'gender' => 'not_specified',
            'roles' => ['Supervisor'],
            'profile_photo' => UploadedFile::fake()->image('avatar.png'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    $target->refresh();

    expect($target->name)->toBe('Updated Profile User')
        ->and($target->national_id)->toBe('AA-2026-UPDATED')
        ->and($target->phone_number)->toBe('+251922222222')
        ->and($target->gender)->toBe('not_specified')
        ->and($target->hasRole('Supervisor'))->toBeTrue()
        ->and($target->profile_photo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($target->profile_photo_path);
});

test('blank password on edit does not change the existing password', function (): void {
    $target = makeTargetUser();
    $originalHash = $target->password;

    $this->actingAs(userMgmtAdmin())
        ->patch(route('users.update', $target), [
            'name' => 'Password Unchanged',
            'email' => $target->email,
            'status' => 'active',
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->password)->toBe($originalHash)
        ->and(Hash::check('password', $target->fresh()->password))->toBeTrue();
});

test('national id unique validation ignores current user on update', function (): void {
    $target = User::factory()->create([
        'status' => 'active',
        'national_id' => 'AA-UNIQUE-SELF',
    ]);

    $this->actingAs(userMgmtAdmin())
        ->patch(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'status' => 'active',
            'national_id' => 'AA-UNIQUE-SELF',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));
});

// ── Deactivate ─────────────────────────────────────────────────────────────

test('users without archive permission cannot deactivate a user', function (): void {
    $target = makeTargetUser();

    $this->actingAs(userMgmtViewer())
        ->post(route('users.deactivate', $target))
        ->assertForbidden();
});

test('users with archive permission can deactivate another user', function (): void {
    $actor = userMgmtAdmin();
    $target = makeTargetUser();

    $this->actingAs($actor)
        ->post(route('users.deactivate', $target))
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->status)->toBe('inactive');
});

test('users cannot deactivate themselves', function (): void {
    $actor = userMgmtAdmin();

    $this->actingAs($actor)
        ->post(route('users.deactivate', $actor))
        ->assertForbidden();
});

test('last active super admin cannot be deactivated', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $target = makeTargetUser();
    $target->assignRole('Super Admin');

    $this->actingAs(userMgmtAdmin())
        ->post(route('users.deactivate', $target))
        ->assertForbidden();

    expect($target->fresh()->status)->toBe('active');
});

test('a non-super-admin cannot edit a super admin account at all', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $target = makeTargetUser();
    $target->assignRole('Super Admin');

    // UserPolicy::update blocks this before any role validation runs — editing
    // a Super Admin's email/password would be an account takeover.
    $this->actingAs(userMgmtAdmin())
        ->patch(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'status' => 'active',
            'roles' => [],
        ])
        ->assertForbidden();

    expect($target->fresh()->hasRole('Super Admin'))->toBeTrue();
});

test('last super admin role cannot be removed', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $target = makeTargetUser();
    $target->assignRole('Super Admin');

    // UserPolicy::update lets a Super Admin edit their own account, and any
    // second Super Admin would mean $target is no longer the last one — so the
    // guard is only reachable when the sole Super Admin edits themselves.
    $this->actingAs($target)
        ->patch(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'status' => 'active',
            'roles' => [],
        ])
        ->assertSessionHasErrors('roles');

    expect($target->fresh()->hasRole('Super Admin'))->toBeTrue();
});

// ── Restore ────────────────────────────────────────────────────────────────

test('users without restore permission cannot restore a user', function (): void {
    $target = makeTargetUser();
    $target->update(['status' => 'inactive']);

    $this->actingAs(userMgmtViewer())
        ->post(route('users.restore', $target))
        ->assertForbidden();
});

test('users with restore permission can restore a deactivated user', function (): void {
    $target = makeTargetUser();
    $target->update(['status' => 'inactive']);

    $this->actingAs(userMgmtAdmin())
        ->post(route('users.restore', $target))
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->status)->toBe('active');
});
