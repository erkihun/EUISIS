<?php

use Modules\Court\Domain\Models\CourtStaffMember as User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/court/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/court/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/court/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/court/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/court/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});


test('sessions page shows real device and browser info from stored sessions', function () {
    // The Devices tab lists sessions only with the database driver (the live configuration).
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    \Illuminate\Support\Facades\DB::table('sessions')->insert([
        'id' => 'other-session-id',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->subMinutes(30)->getTimestamp(),
    ]);

    // Sessions are the profile page's Devices tab now.
    $this->actingAs($user)
        ->get('/court/profile')
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('devices.sessions', fn ($sessions) => collect($sessions)->contains(
                fn ($row) => $row['id'] === \Modules\Court\Application\Support\SessionRows::publicKey('other-session-id') && $row['os'] === 'macOS' && $row['browser'] === 'Safari 17' && $row['ip_address'] === '203.0.113.7'
            )));
});

test('sessions page shows exact os and browser version from stored client hints', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    \Illuminate\Support\Facades\DB::table('sessions')->insert([
        'id' => 'hinted-session-id',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0',
        'payload' => base64_encode(serialize(['client_hints' => [
            'platform' => 'Windows',
            'platform_version' => '15.0.0',
            'brands' => '"Chromium";v="150.0.7204.50", "Microsoft Edge";v="150.0.3595.20", "Not?A_Brand";v="8.0.0.0"',
        ]])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($user)
        ->get('/court/profile')
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('devices.sessions', fn ($sessions) => collect($sessions)->contains(
                fn ($row) => $row['id'] === \Modules\Court\Application\Support\SessionRows::publicKey('hinted-session-id') && $row['os'] === 'Windows 11' && $row['browser'] === 'Microsoft Edge 150.0.3595.20'
            )));
});



test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/court/profile')
        ->delete('/court/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/court/profile');

    $this->assertNotNull($user->fresh());
});
