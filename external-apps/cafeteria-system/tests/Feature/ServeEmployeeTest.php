<?php

declare(strict_types=1);

use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaApiLog;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use CafeteriaSystem\Services\EuisisApiClient;
use CafeteriaSystem\Services\ServeEmployeeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Scan-to-serve pipeline against a MOCKED EUISIS API.
 *
 * The cafeteria has no EUISIS database connection, so every test here drives
 * the real decision path purely through HTTP responses.
 */
beforeEach(function (): void {
    config()->set('euisis.base_url', 'https://euisis.test');
    config()->set('euisis.token', 'test-token');

    $this->provider = CafeteriaProvider::query()->create([
        'code' => 'CAF-001',
        'name' => 'Main Cafeteria',
        'status' => 'active',
    ]);

    $this->cafeteria = Cafeteria::query()->create([
        'provider_id' => $this->provider->id,
        'name' => 'Main Cafeteria',
        'code' => 'CAF-MAIN',
        'status' => 'active',
    ]);

    // The pipeline now requires the employee's organization to be assigned.
    CafeteriaOrganizationAssignment::query()->create([
        'cafeteria_id' => $this->cafeteria->id,
        'organization_code' => 'ORG-1',
        'organization_name_snapshot' => 'Bole Sub-city',
        'status' => 'active',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    $this->service = app(ServeEmployeeService::class);
});

/** @param array<string, mixed> $overrides */
function verifiedCard(array $overrides = []): array
{
    return array_merge([
        'valid' => true,
        'status' => 'active',
        'employee_id' => '019f0000-0000-7000-8000-000000000001',
        'card' => ['card_number' => 'IDC-2026-000001'],
        'employee' => [
            'employee_number' => 'EMP-2026-000001',
            'full_name' => 'Abebe Bekele',
            'status' => 'active',
        ],
        'organization' => ['code' => 'ORG-1', 'name_en' => 'Bole Sub-city', 'name_am' => null],
        'position' => ['code' => 'POS-1', 'title_en' => 'HR Officer', 'title_am' => null],
    ], $overrides);
}

it('serves an employee whose card is active and eligible', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(verifiedCard(), 200),
        'https://euisis.test/api/v1/employees/*/service-eligibility*' => Http::response(['eligible' => true], 200),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeTrue()
        ->and($result['result_code'])->toBe('served')
        ->and($result['transaction'])->not->toBeNull();

    // Only the minimal verified snapshot is stored.
    $stored = CafeteriaServiceTransaction::query()->firstOrFail();
    expect($stored->employee_number)->toBe('EMP-2026-000001')
        ->and($stored->organization_name)->toBe('Bole Sub-city')
        ->and($stored->status)->toBe('served');
});

it('blocks service when the card is not active', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(
            verifiedCard(['valid' => false, 'status' => 'expired']),
            200,
        ),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('card_expired')
        ->and(CafeteriaServiceTransaction::query()->count())->toBe(0);
});

it('blocks service for a revoked or lost card', function (string $status): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(
            verifiedCard(['valid' => false, 'status' => $status]),
            200,
        ),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        ->and(CafeteriaServiceTransaction::query()->count())->toBe(0);
})->with(['revoked', 'lost', 'suspended', 'replaced']);

it('blocks service when the employee is inactive', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(
            verifiedCard(['employee' => [
                'employee_number' => 'EMP-2026-000001',
                'full_name' => 'Abebe Bekele',
                'status' => 'terminated',
            ]]),
            200,
        ),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('employee_inactive');
});

it('blocks service when euisis reports the employee is not eligible', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(verifiedCard(), 200),
        'https://euisis.test/api/v1/employees/*/service-eligibility*' => Http::response(
            ['eligible' => false, 'reason_code' => 'id_card_expired'],
            403,
        ),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('id_card_expired');
});

it('blocks a duplicate service for the same employee on the same day', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(verifiedCard(), 200),
        'https://euisis.test/api/v1/employees/*/service-eligibility*' => Http::response(['eligible' => true], 200),
    ]);

    $first = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);
    $second = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($first['served'])->toBeTrue()
        ->and($second['served'])->toBeFalse()
        ->and($second['result_code'])->toBe('already_served_today')
        ->and(CafeteriaServiceTransaction::query()->count())->toBe(1);
});

it('fails closed and logs the error when euisis is unreachable', function (): void {
    Http::fake([
        'https://euisis.test/*' => fn () => throw new ConnectionException('unreachable'),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        // Specific reason, so an operator can tell this from a bad token.
        ->and($result['result_code'])->toBe('euisis_unreachable');

    $log = CafeteriaApiLog::query()->firstOrFail();
    expect($log->success)->toBeFalse()
        ->and($log->error_code)->toBe('connection_failed');
});

it('fails closed when the api token lacks the required scope', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(
            ['message' => 'Forbidden.', 'error_code' => 'missing_scope'],
            403,
        ),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        ->and(CafeteriaApiLog::query()->first()?->error_code)->toBe('missing_scope');
});

it('fails closed when the api token has been revoked', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        ->and(CafeteriaApiLog::query()->first()?->error_code)->toBe('unauthorized');
});

it('never stores sensitive employee fields', function (): void {
    Http::fake([
        // Even if EUISIS were to return extra fields, they must not persist.
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(
            verifiedCard(['employee' => [
                'employee_number' => 'EMP-2026-000001',
                'full_name' => 'Abebe Bekele',
                'status' => 'active',
                'national_id' => '1234567890123456',
                'phone' => '0911000000',
            ]]),
            200,
        ),
        'https://euisis.test/api/v1/employees/*/service-eligibility*' => Http::response(['eligible' => true], 200),
    ]);

    $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    $row = json_encode(CafeteriaServiceTransaction::query()->firstOrFail()->toArray());

    expect($row)->not->toContain('1234567890123456')
        ->and($row)->not->toContain('0911000000')
        ->and($row)->not->toContain('national_id');
});

it('logs every api call as metadata without response bodies', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(verifiedCard(), 200),
        'https://euisis.test/api/v1/employees/*/service-eligibility*' => Http::response(['eligible' => true], 200),
    ]);

    $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    $logs = CafeteriaApiLog::query()->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->every(fn ($log): bool => $log->success === true))->toBeTrue();

    // The log table has no body/payload column at all.
    expect(array_keys($logs->first()->getAttributes()))
        ->not->toContain('response_body')
        ->not->toContain('payload');
});

it('names the specific transport failure instead of a generic message', function (string $fixture, int $status, string $expected): void {
    config()->set('euisis.token', $fixture === 'missing_api_token' ? '' : 'test-token');

    if ($fixture !== 'missing_api_token') {
        Http::fake([
            'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(
                $fixture === 'missing_scope' ? ['error_code' => 'missing_scope'] : [],
                $status,
            ),
        ]);
    }

    // EuisisApiClient is a container singleton that reads the token once, so
    // it must be rebuilt after this test rewrites the config.
    app()->forgetInstance(EuisisApiClient::class);

    $service = app(ServeEmployeeService::class);
    $result = $service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteria);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe($expected);
})->with([
    ['missing_api_token', 0, 'missing_api_token'],
    ['unauthorized', 401, 'api_token_rejected'],
    ['missing_scope', 403, 'api_scope_denied'],
    ['rate_limited', 429, 'api_rate_limited'],
]);
