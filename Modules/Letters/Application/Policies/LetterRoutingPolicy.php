<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Policies;

use App\Models\User;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\LetterRouting;

/** A routing step is acted on by its addressee, or by the institution's registry desk. */
final class LetterRoutingPolicy
{
    public function __construct(private readonly LettersAccess $access) {}

    public function act(User $user, LetterRouting $routing): bool
    {
        $routing->loadMissing('registration');

        return $this->access->canActOnRouting($user, $routing);
    }
}
