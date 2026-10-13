<?php

namespace Tests;

use App\Services\Security\SessionActivityService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'pgsql') {
            $baseDatabase = (string) env('DB_TEST_DATABASE', '');
            $database = (string) config('database.connections.pgsql.database');
            $token = ParallelTesting::token();
            // Unit tests without a database trait stay on the base name in parallel workers.
            $allowedDatabases = [$baseDatabase];

            if ($token) {
                $allowedDatabases[] = "{$baseDatabase}_test_{$token}";
            }

            if (! str_ends_with($baseDatabase, '_test') || ! in_array($database, $allowedDatabases, true)) {
                throw new \RuntimeException('PostgreSQL tests require DB_TEST_DATABASE to name a dedicated database ending in _test (or its matching parallel worker database).');
            }
        }
    }

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
