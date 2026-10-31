<?php

declare(strict_types=1);

namespace Modules\Court\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Court\Domain\Models\Concerns\ApplicantMessages;

final class ApplicantAccountMessages implements ApplicantMessages
{
    public function verifyEmail(): Notification
    {
        return new ApplicantVerifyEmail;
    }

    public function resetPassword(string $token): Notification
    {
        return new ApplicantResetPassword($token);
    }
}
