<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Services\Dashboard\DashboardChartService;
use Carbon\CarbonImmutable;

function demographicCharts(bool $permitted = true, bool $global = true): array
{
    $can = array_fill_keys(['employees', 'organizations', 'positions', 'cards', 'verification', 'entitlements', 'transactions', 'providers', 'transfers', 'audit'], false);
    $can['employees'] = $permitted;

    return app(DashboardChartService::class)->charts([
        'global_access' => $global,
        'organization_ids' => [],
        'top_limit' => 10,
        'date_from' => CarbonImmutable::today()->subDays(30),
        'date_to' => CarbonImmutable::today()->endOfDay(),
    ], $can);
}

test('employee demographics count birthday boundaries and unspecified details without exposing birth dates', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));

    $birthDates = ['2006-10-05', '2006-10-04', '1996-10-05', '1996-10-04', '1986-10-04', '1976-10-04', '1966-10-04', null, '2026-10-05'];
    foreach ($birthDates as $index => $birthDate) {
        Employee::query()->create([
            'employee_number' => 'DEMOGRAPHIC-'.$index,
            'first_name' => 'Example',
            'last_name' => 'Employee',
            'full_name' => 'Example Employee',
            'status' => 'active',
            'date_of_birth' => $birthDate,
            'gender' => $index === 7 ? null : ($index % 2 === 0 ? 'male' : 'female'),
            'employment_type' => $index === 7 ? null : 'permanent',
        ]);
    }

    $charts = demographicCharts();
    expect(array_column($charts['employeesByAge'], 'value', 'key'))->toBe([
        'under_20' => 1, '20_29' => 2, '30_39' => 1, '40_49' => 1, '50_59' => 1, '60_plus' => 1, 'unknown' => 2,
    ])->and(array_column($charts['employeesBySex'], 'value', 'key'))->toEqual([
        'male' => 5, 'female' => 3, 'unknown' => 1,
    ])->and(array_column($charts['employeesByEmploymentType'], 'value', 'key'))->toEqual([
        'permanent' => 8, 'unknown' => 1,
    ])->and(json_encode($charts))->not->toContain('2006-10-05');

    foreach (['employeesByAge', 'employeesBySex', 'employeesByEmploymentType'] as $key) {
        expect(array_sum(array_column($charts[$key], 'value')))->toBe(9);
        expect(demographicCharts(false)[$key])->toBe([]);
        expect(array_sum(array_column(demographicCharts(true, false)[$key], 'value')))->toBe(0);
    }
});
