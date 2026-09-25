<?php

namespace Tests;

use App\Services\Security\SessionActivityService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * `actingAs()` stands for signing in. In a browser that starts a fresh
     * authenticated session: a new idle clock and a password-hash binding for
     * THIS user. Tests reuse one in-memory session across users and across
     * clock jumps, so reset exactly what a real sign-in would (see
     * docs/session-management.md). Tests of the idle policy itself call
     * actingAs() once and then make plain requests.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $session = $this->app['session.store'];

        foreach (SessionActivityService::GUARDS as $name) {
            $session->forget('password_hash_'.$name);
        }
        $session->forget(SessionActivityService::LAST_ACTIVITY_KEY);

        return parent::actingAs($user, $guard);
    }
}
