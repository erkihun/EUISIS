<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Report unsafe production configuration without printing any secret.
 *
 * Phase-2 deliverable. The settings below are safe as they stand for local
 * development — this command exists so a deployment gate can assert them for
 * production instead of anyone changing the local defaults and breaking
 * development over HTTP.
 *
 * Exit code 1 when any FAIL is present, so CI or a deploy step can gate on it.
 */
class SecurityProductionCheck extends Command
{
    protected $signature = 'security:production-check {--strict : Treat warnings as failures}';

    protected $description = 'Report configuration that is unsafe for a production deployment';

    public function handle(): int
    {
        $production = app()->isProduction();

        $this->components->info(sprintf(
            'Environment: %s (%s)',
            config('app.env'),
            $production ? 'production rules enforced' : 'non-production — reported for awareness',
        ));

        /** @var array<int, array{0: string, 1: bool, 2: string}> $checks */
        $checks = [
            ['APP_DEBUG is off', config('app.debug') === false, 'Stack traces and configuration leak to users when debug is on.'],
            ['APP_KEY is set', filled(config('app.key')), 'Sessions, signed URLs and encrypted columns all depend on it.'],
            ['Session cookies are Secure', config('session.secure') === true, 'Without it the session cookie is sent over plain HTTP.'],
            ['Session cookies are HttpOnly', config('session.http_only') === true, 'Without it JavaScript can read the session cookie.'],
            ['Session SameSite is lax or strict', in_array(config('session.same_site'), ['lax', 'strict'], true), 'SameSite=none exposes the session to cross-site requests.'],
            ['Session payload is encrypted', config('session.encrypt') === true, 'Session contents are readable at rest without it.'],
            ['Public self-registration is off', config('security.registration_enabled') === false, 'Anyone knowing an employee number could open an account.'],
            ['CORS does not allow every origin', ! in_array('*', (array) config('cors.allowed_origins'), true), 'A wildcard origin with credentials exposes the API to any site.'],
            ['Log level is not debug', config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug', 'Debug logging records far more request detail than production needs.'],
        ];

        $failed = 0;

        foreach ($checks as [$label, $passed, $why]) {
            if ($passed) {
                $this->components->twoColumnDetail($label, '<fg=green>PASS</>');

                continue;
            }

            // Outside production these are expected, so they are warnings
            // unless --strict is used by a deployment gate.
            $fatal = $production || $this->option('strict');
            $failed += $fatal ? 1 : 0;

            $this->components->twoColumnDetail($label, $fatal ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>');
            $this->components->bulletList([$why]);
        }

        if ($failed > 0) {
            $this->components->error($failed.' setting(s) unsafe for production.');

            return self::FAILURE;
        }

        $this->components->info('No unsafe production settings detected.');

        return self::SUCCESS;
    }
}
