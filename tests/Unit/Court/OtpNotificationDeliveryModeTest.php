<?php

declare(strict_types=1);

use Modules\Court\Application\Notifications\ApplicantEmailOtp;
use Modules\Court\Application\Notifications\PasswordResetOtp;
use Illuminate\Contracts\Queue\ShouldQueue;

it('sends OTP notifications synchronously so SMTP failures reach the request', function (string $notificationClass): void {
    $notification = new $notificationClass('123456');

    expect($notification)->not->toBeInstanceOf(ShouldQueue::class);
})->with([
    ApplicantEmailOtp::class,
    PasswordResetOtp::class,
]);
