<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

use App\Actions\Users\CreateUserAction;
use App\Models\User;
use Modules\Court\Domain\Models\CourtStaffMember;

/**
 * The platform account a new court staff member works through (ADR 0020):
 * the EUISIS account that already has the email, or a new one created by the
 * platform's own action with a one-time password (court-ms sent a reset link
 * for a random password; the court's Users page still does).
 */
final readonly class CourtStaffAccounts
{
    public function __construct(private CreateUserAction $createUser) {}

    public function accountFor(string $email, string $name, string $password, CourtStaffMember $actor): User
    {
        return User::query()->where('email', $email)->first()
            ?? $this->createUser->execute(['name' => $name, 'email' => $email, 'password' => $password, 'status' => 'active'], $actor->platformAccount());
    }
}
