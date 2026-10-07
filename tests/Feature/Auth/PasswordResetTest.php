<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'Quiet river stones at noon 482',
            'password_confirmation' => 'Quiet river stones at noon 482',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('a reset link reaches the account even when the email is typed in another case', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'reset.case@example.test']);

    $this->post('/forgot-password', ['email' => '  Reset.Case@Example.TEST '])
        ->assertSessionHas('status', __('password-policy.reset_link_sent'));

    Notification::assertSentTo($user, ResetPassword::class);
});

test('a mail server failure gives the same answer and is recorded for administrators', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    $user = User::factory()->create(['email' => 'reset.fail@example.test']);

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertRedirect()
        ->assertSessionHas('status', __('password-policy.reset_link_sent'));

    expect(App\Models\AuditLog::query()->where('event_type', 'password_reset_requested')->latest('id')->value('reason'))
        ->toBe('password_reset_email_failed');
});
