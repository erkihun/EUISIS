<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Base for the demo dataset seeders (docs/demo-seed-data.md).
 *
 * Every demo seeder refuses production — also when run on its own with
 * --class — and runs with external side effects switched off. There is no
 * production override.
 */
abstract class DemoSeeder extends Seeder
{
    /** @param  array<string, mixed>  $parameters */
    public function __invoke(array $parameters = [])
    {
        self::assertAllowed();
        self::suppressExternalSideEffects();

        return parent::__invoke($parameters);
    }

    public static function assertAllowed(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo data seeding is disabled in production.');
        }
    }

    /**
     * No SMS, no real mail, nothing left on a queue for a worker to send later.
     * Applies to this process only.
     */
    public static function suppressExternalSideEffects(): void
    {
        config([
            'security.external_usage.sms.enabled' => false,
            'mail.default' => 'array',
            'queue.default' => 'sync',
        ]);
    }

    /**
     * The demo accounts' password: DEMO_USER_PASSWORD, or the documented
     * development fallback on a developer machine and in tests. Anywhere else
     * the variable is required, so no known password reaches a shared server.
     */
    public static function demoPassword(): string
    {
        $configured = config('demo.user_password');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (app()->environment(['local', 'testing'])) {
            return 'password';
        }

        throw new RuntimeException('Set DEMO_USER_PASSWORD before seeding demo data outside the local and testing environments.');
    }
}
