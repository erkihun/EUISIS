<?php

declare(strict_types=1);

it('ships a production template suitable for the employee portal', function (): void {
    $root = dirname(__DIR__, 2);
    $environment = file_get_contents($root.'/.env.production');
    $readiness = file_get_contents($root.'/app/Console/Commands/ProductionReadiness.php');
    $infrastructure = file_get_contents($root.'/docs/infrastructure-security.md');

    expect($environment)
        ->toContain('APP_URL=https://ems.pshrdb.gov.et')
        ->toContain('SESSION_SAME_SITE=lax')
        ->toContain('SANCTUM_STATEFUL_DOMAINS=ems.pshrdb.gov.et')
        ->toContain('CORS_ALLOWED_ORIGINS=https://ems.pshrdb.gov.et')
        ->toContain('DB_PASSWORD=CHANGE_ME')
        ->and($readiness)
        ->toContain('Session SameSite is lax')
        ->and($infrastructure)
        ->toContain('gzip on;')
        ->toContain('gzip_types text/css application/javascript');
});
