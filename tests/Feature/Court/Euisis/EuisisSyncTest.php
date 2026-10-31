<?php

use Modules\Court\Domain\Models\EuisisEmployee;
use Modules\Court\Domain\Models\EuisisOrganization;
use Modules\Court\Domain\Models\EuisisOrganizationUnit;
use Modules\Court\Domain\Models\EuisisPosition;
use Modules\Court\Application\Services\Euisis\EuisisSync;
use Illuminate\Support\Facades\Http;

// Flow 1 of docs/euisis-integration.md: the EUISIS master-data cache.

const EUISIS_SYNC_ORG = '01a0deef-0000-7000-8000-000000000001';
const EUISIS_SYNC_UNIT = '01a0deef-0000-7000-8000-000000000002';
const EUISIS_SYNC_POSITION = '01a0deef-0000-7000-8000-000000000003';
const EUISIS_SYNC_EMPLOYEE = '01a0deef-0000-7000-8000-000000000004';
const EUISIS_SYNC_OTHER_ORG = '01a0deef-0000-7000-8000-000000000009';

beforeEach(function () {
    config([
        'court.euisis.enabled' => true,
        'court.euisis.url' => 'https://euisis.test',
        'court.euisis.token' => 'partner-token',
    ]);
});

function euisisSyncEvent(string $type, string $aggregate, int $sequence, array $data, string $kind = 'upsert'): array
{
    return ['event_id' => (string) Illuminate\Support\Str::uuid(), 'event_type' => $type, 'event_version' => 1, 'aggregate_uuid' => $aggregate, 'sequence' => $sequence, 'change_kind' => $kind, 'data' => $data];
}

function euisisSyncFakeDirectory(array $feed, bool $feedOn = true): void
{
    Http::fake([
        'euisis.test/api/v1/changes/watermark' => $feedOn
            ? Http::response(['cursor' => 'W1', 'as_of' => now()->toIso8601String()])
            : Http::response(['message' => 'The change feed is disabled.', 'error_code' => 'change_feed_disabled'], 404),
        'euisis.test/api/v1/organizations/directory*' => Http::response([
            'data' => [['id' => EUISIS_SYNC_ORG, 'code' => 'BOLE', 'name_en' => 'Bole Sub-city', 'name_am' => 'ቦሌ ክፍለ ከተማ', 'status' => 'active']],
            'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1],
        ]),
        'euisis.test/api/v1/organizations/'.EUISIS_SYNC_ORG.'/units*' => Http::response([
            'data' => [['id' => EUISIS_SYNC_UNIT, 'organization_id' => EUISIS_SYNC_ORG, 'parent_unit_id' => null, 'code' => 'HR', 'name_en' => 'Human Resources', 'name_am' => null, 'status' => 'active']],
            'meta' => ['last_page' => 1],
        ]),
        'euisis.test/api/v1/organizations/'.EUISIS_SYNC_ORG.'/positions*' => Http::response([
            'data' => [['id' => EUISIS_SYNC_POSITION, 'organization_id' => EUISIS_SYNC_ORG, 'organization_unit_id' => EUISIS_SYNC_UNIT, 'code' => 'P-1', 'standard_name' => 'HR Officer', 'standard_name_am' => null, 'position_status' => 'active']],
            'meta' => ['last_page' => 1],
        ]),
        'euisis.test/api/v1/changes?*' => Http::sequence()->push($feed)->whenEmpty(Http::response(['data' => [], 'next_cursor' => 'W9', 'has_more' => false])),
    ]);
}

test('the first run bootstraps organizations and units, applies the feed from the watermark and keeps its cursor', function () {
    euisisSyncFakeDirectory(['data' => [
        euisisSyncEvent('organization.updated', EUISIS_SYNC_ORG, 4, ['organization_uuid' => EUISIS_SYNC_ORG, 'code' => 'BOLE', 'name_en' => 'Bole Sub-city Administration', 'name_am' => 'ቦሌ ክፍለ ከተማ', 'status' => 'active']),
        euisisSyncEvent('organization.created', EUISIS_SYNC_OTHER_ORG, 1, ['organization_uuid' => EUISIS_SYNC_OTHER_ORG, 'code' => 'YEKA', 'name_en' => 'Yeka Sub-city', 'name_am' => null, 'status' => 'active']),
        // A position of an organization whose positions nobody tracks is not cached.
        euisisSyncEvent('position.created', EUISIS_SYNC_POSITION, 1, ['position_uuid' => EUISIS_SYNC_POSITION, 'organization_uuid' => EUISIS_SYNC_ORG, 'title_en' => 'HR Officer', 'is_active' => true]),
    ], 'next_cursor' => 'W2', 'has_more' => false]);

    $summary = app(EuisisSync::class)->run();

    expect($summary)->toMatchArray(['bootstrapped' => true, 'organizations' => 1, 'units' => 1, 'events' => 3, 'applied' => 2])
        ->and(EuisisOrganization::query()->find(EUISIS_SYNC_ORG)->name_en)->toBe('Bole Sub-city Administration')
        ->and(EuisisOrganization::query()->find(EUISIS_SYNC_OTHER_ORG)->name_en)->toBe('Yeka Sub-city')
        ->and(EuisisOrganizationUnit::query()->count())->toBe(1)
        ->and(EuisisPosition::query()->count())->toBe(0)
        ->and(app(EuisisSync::class)->state(EuisisSync::CURSOR_KEY))->toBe('W2');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer partner-token'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/changes?') && str_contains($request->url(), 'cursor=W1'));
});

test('feed events older than the cached record, or for people nobody cached, change nothing', function () {
    euisisSyncFakeDirectory(['data' => [], 'next_cursor' => 'W2', 'has_more' => false]);
    $sync = app(EuisisSync::class);
    $sync->run();
    EuisisOrganization::query()->whereKey(EUISIS_SYNC_ORG)->update(['last_sequence' => 7]);

    expect($sync->apply(euisisSyncEvent('organization.updated', EUISIS_SYNC_ORG, 6, ['organization_uuid' => EUISIS_SYNC_ORG, 'name_en' => 'Stale name'])))->toBeFalse()
        ->and(EuisisOrganization::query()->find(EUISIS_SYNC_ORG)->name_en)->toBe('Bole Sub-city')
        ->and($sync->apply(euisisSyncEvent('organization.status_changed', EUISIS_SYNC_ORG, 8, ['organization_uuid' => EUISIS_SYNC_ORG, 'status' => 'archived', 'previous_status' => 'active'], 'tombstone')))->toBeTrue()
        ->and(EuisisOrganization::query()->find(EUISIS_SYNC_ORG)->status)->toBe('archived')
        ->and($sync->apply(euisisSyncEvent('employee.status_changed', EUISIS_SYNC_EMPLOYEE, 2, ['employee_uuid' => EUISIS_SYNC_EMPLOYEE, 'status' => 'terminated'])))->toBeFalse()
        ->and(EuisisEmployee::query()->count())->toBe(0);
});

test('a linked employee is cached with their organization positions and follows their transfers', function () {
    euisisSyncFakeDirectory(['data' => [], 'next_cursor' => 'W2', 'has_more' => false]);
    $sync = app(EuisisSync::class);
    $sync->run();

    $employee = $sync->cacheEmployee([
        'id' => EUISIS_SYNC_EMPLOYEE, 'employee_number' => 'AA-0042', 'full_name' => 'Abebe Kebede', 'full_name_en' => 'Abebe Kebede',
        'employment_status' => 'active', 'organization_id' => EUISIS_SYNC_ORG, 'organization_unit_id' => EUISIS_SYNC_UNIT, 'position_id' => EUISIS_SYNC_POSITION,
        'active_assignment' => ['id' => '01a0deef-0000-7000-8000-0000000000aa'],
    ]);
    expect($employee->position_uuid)->toBe(EUISIS_SYNC_POSITION)
        ->and(EuisisPosition::query()->find(EUISIS_SYNC_POSITION)?->title_en)->toBe('HR Officer');

    $sync->apply(euisisSyncEvent('employee.transferred', EUISIS_SYNC_EMPLOYEE, 1, [
        'employee_uuid' => EUISIS_SYNC_EMPLOYEE, 'organization_uuid' => EUISIS_SYNC_ORG, 'unit_uuid' => null, 'position_uuid' => null,
        'assignment_uuid' => '01a0deef-0000-7000-8000-0000000000bb',
    ]));
    expect($employee->refresh()->assignment_uuid)->toBe('01a0deef-0000-7000-8000-0000000000bb');
});

test('with the EUISIS change feed off, the directory is still mirrored and refreshed at most hourly', function () {
    euisisSyncFakeDirectory(['data' => [], 'next_cursor' => 'W2', 'has_more' => false], feedOn: false);
    $sync = app(EuisisSync::class);

    expect($sync->run())->toMatchArray(['bootstrapped' => true, 'organizations' => 1, 'feed' => false])
        ->and($sync->state(EuisisSync::CURSOR_KEY))->toBeNull()
        ->and(EuisisOrganization::query()->count())->toBe(1)
        ->and($sync->run())->toMatchArray(['bootstrapped' => false, 'feed' => false]);

    $this->travel(61)->minutes();
    expect($sync->run())->toMatchArray(['bootstrapped' => true]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/changes?'));
});

test('the sync command reports a switched-off connection without calling EUISIS', function () {
    config(['court.euisis.enabled' => false]);
    Http::fake();

    $this->artisan('court:euisis-sync')->expectsOutputToContain('EUISIS sync is off')->assertSuccessful();
    Http::assertNothingSent();
});
