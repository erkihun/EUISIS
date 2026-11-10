<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Policies;

use App\Models\User;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\LetterRegistration;

/** One institution's registry entry: receiving, routing and closing belong to that institution's desk. */
final class LetterRegistrationPolicy
{
    public function __construct(private readonly LettersAccess $access) {}

    public function receive(User $user, LetterRegistration $registration): bool
    {
        return $this->access->canReceive($user, $registration);
    }

    public function route(User $user, LetterRegistration $registration): bool
    {
        return $this->access->canRoute($user, $registration);
    }

    public function close(User $user, LetterRegistration $registration): bool
    {
        return $this->access->canClose($user, $registration);
    }

    public function acknowledge(User $user, LetterRegistration $registration): bool
    {
        $registration->loadMissing('letter');

        return $this->access->canAcknowledge($user, $registration);
    }
}
