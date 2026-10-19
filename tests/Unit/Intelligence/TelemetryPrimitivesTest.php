<?php

declare(strict_types=1);

use App\Services\Intelligence\System\ErrorFingerprinter;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\Telemetry\RequestCounters;

it('maps routes to coarse groups without carrying identifiers', function (): void {
    expect(RouteGroups::of('service-feedback.store', '/service-feedback/abc'))->toBe(RouteGroups::PUBLIC_FEEDBACK)
        ->and(RouteGroups::of(null, '/feedback/qr/123'))->toBe(RouteGroups::PUBLIC_FEEDBACK)
        ->and(RouteGroups::of('employee.daily-activities.store', '/my-portal/daily-activity'))->toBe(RouteGroups::DAILY_ACTIVITY)
        ->and(RouteGroups::of('cafeteria.scan', '/cafeteria/scan'))->toBe(RouteGroups::CAFETERIA)
        ->and(RouteGroups::of('employee.profile', '/my-portal/profile'))->toBe(RouteGroups::EMPLOYEE_PORTAL)
        ->and(RouteGroups::of('login', '/login'))->toBe(RouteGroups::AUTH)
        ->and(RouteGroups::of(null, '/api/v1/employees/42'))->toBe(RouteGroups::API)
        ->and(RouteGroups::of('employees.index', '/employees'))->toBe(RouteGroups::ADMIN)
        ->and(RouteGroups::of(null, '/unknown'))->toBe(RouteGroups::OTHER);
});

it('normalises an error message so the same failure reads the same', function (): void {
    $a = ErrorFingerprinter::normalize("No query results for model [App\\Models\\Employee] 0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b");
    $b = ErrorFingerprinter::normalize("No query results for model [App\\Models\\Employee] 0199ffff-3c4d-7e5f-8a9b-0c1d2e3f4a5b");
    $c = ErrorFingerprinter::normalize("Call to a member function name() on null at 'C:\\www\\app\\Services\\X.php' line 42 for user@example.test");

    expect($a)->toBe($b)
        ->and($a)->toContain('{uuid}')
        ->and($c)->toContain("'{value}'")->toContain('{n}')
        ->and($c)->not->toContain('user@example.test')
        ->and(ErrorFingerprinter::normalize(''))->toBe('(no message)')
        ->and(mb_strlen(ErrorFingerprinter::normalize(str_repeat('x', 2000))))->toBeLessThanOrEqual(500);
});

/* The counter tests need the container (cache, config): tests/Feature/Intelligence/TelemetryTest.php. */
it('buckets latency on fixed bounds', function (): void {
    $counters = new RequestCounters;
    expect($counters->latencyBucket(99))->toBe('b100')
        ->and($counters->latencyBucket(300))->toBe('b1000')
        ->and($counters->latencyBucket(9000))->toBe('binf')
        ->and($counters->minuteKey(125))->toBe(120);
});
