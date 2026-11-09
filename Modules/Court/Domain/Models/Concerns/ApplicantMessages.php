<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models\Concerns;

use Illuminate\Notifications\Notification;

/**
 * The messages an applicant account sends itself (email check, password reset).
 * The Application layer writes them; CourtServiceProvider binds the implementation.
 */
interface ApplicantMessages
{
    public function verifyEmail(): Notification;

    public function resetPassword(string $token): Notification;
}
