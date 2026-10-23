<?php

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone_number' => '+251911111111',
            'gender' => 'female',
            'national_id' => '1234567890123456',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertSame('+251911111111', $user->phone_number);
    $this->assertSame('female', $user->gender);
    $this->assertSame('1234567890123456', $user->national_id);
    $this->assertNull($user->email_verified_at);

    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $user->id,
        'event_type' => AuditEventType::ProfileUpdated->value,
    ]);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user cannot delete their own account from profile settings', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertAuthenticatedAs($user);
    $this->assertNotNull($user->fresh());
    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $user->id,
        'event_type' => AuditEventType::UserDeactivationBlockedSelf->value,
    ]);
});

test('profile update can upload a profile photo', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'profile_photo' => UploadedFile::fake()->image('avatar.webp'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    expect($user->profile_photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->profile_photo_path);
    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $user->id,
        'event_type' => AuditEventType::ProfilePhotoUpdated->value,
    ]);
});

test('profile update rejects invalid gender and profile photo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'gender' => 'invalid',
            'profile_photo' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors(['gender', 'profile_photo']);
});

test('profile update does not allow role changes', function () {
    Role::findOrCreate('Super Admin', 'web');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => ['Super Admin'],
        ]);

    expect($user->fresh()->hasRole('Super Admin'))->toBeFalse();
});

test('shared auth props include avatar data and hide national id', function () {
    $user = User::factory()->create(['national_id' => 'AA-SECRET']);

    $this->actingAs($user)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.user.profile_photo_url')
            ->has('auth.user.initials')
            ->missing('auth.user.national_id'));
});
