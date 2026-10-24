<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Whether the signed-in person is working in the Core workspace.
 *
 * Opening /core sets a session flag; leaving Core, signing out or losing
 * `core.access` ends it. The flag only changes the navigation the shell
 * draws. Pages, permissions and organization scope stay exactly as they are
 * in the full application.
 */
final class CoreWorkspace
{
    public const SESSION_KEY = 'workspace';

    public const NAME = 'core';

    public const PERMISSION = 'core.access';

    public function enter(Session $session): void
    {
        $session->put(self::SESSION_KEY, self::NAME);
    }

    public function leave(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    public function active(?User $user, Session $session): bool
    {
        return $user !== null
            && $session->get(self::SESSION_KEY) === self::NAME
            && $user->can(self::PERMISSION);
    }
}
