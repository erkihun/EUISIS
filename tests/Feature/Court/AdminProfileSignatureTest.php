<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('user cannot upload a signature through self profile', function (): void {
    Storage::fake('court_public');
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->from(route('court.profile.edit'))->patch(route('court.profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'signature' => UploadedFile::fake()->image('signature.png', 500, 180),
    ])->assertRedirect(route('court.profile.edit'))->assertSessionHasErrors('signature');

    expect($user->fresh()->signature_path)->toBeNull();
    Storage::disk('court_public')->assertDirectoryEmpty('signatures');
});

test('user cannot replace or remove an existing signature through self profile', function (): void {
    Storage::fake('court_public');
    $signature = UploadedFile::fake()->image('existing.png')->store('signatures', 'court_public');
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'signature_path' => $signature,
    ]);

    $this->actingAs($user)->from(route('court.profile.edit'))->patch(route('court.profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'signature' => UploadedFile::fake()->image('replacement.png'),
        'remove_signature' => '1',
    ])->assertRedirect(route('court.profile.edit'))
        ->assertSessionHasErrors(['signature', 'remove_signature']);

    expect($user->fresh()->signature_path)->toBe($signature);
    Storage::disk('court_public')->assertExists($signature);
});

test('self profile page displays signature as read only', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // The profile page (Profile/Edit) shows the signature in a read-only card; the form has no signature field.
    $this->actingAs($user)->get(route('court.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Profile/Edit')
            ->where('signature.url', null)
            ->where('signature.read_only', true))
        ->assertDontSee('name="signature"', false)
        ->assertDontSee('name="remove_signature"', false);
});
