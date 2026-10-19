<?php

declare(strict_types=1);

use App\Models\IntelligenceForecast;
use App\Models\User;
use App\Services\Intelligence\Forecasting\ForecastSnapshotService;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\SystemContext;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\SituationRoomScenario;

/*
 * Governance page (grants, no secrets) and forecast snapshots: a run writes
 * rows that are never overwritten, evaluation fills actuals and errors for
 * months that have passed, and quality summarises MAE / MAPE per metric.
 */

function govUser(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('gov-'.md5(implode(',', $permissions)), 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

beforeEach(function (): void {
    config(['cache.default' => 'array', 'intelligence.ai.openai_compatible.api_key' => 'sk-should-never-appear']);
    foreach (IntelligencePermissions::all() as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('governance needs its grant and shows the gateway and prompt catalog without secrets', function (): void {
    $this->actingAs(govUser([IntelligencePermissions::VIEW, IntelligencePermissions::EXECUTIVE]))->get(route('intelligence.governance.index'))->assertForbidden();

    $response = $this->actingAs(govUser([IntelligencePermissions::VIEW, IntelligencePermissions::GOVERNANCE]))->get(route('intelligence.governance.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Governance/Index')
            ->where('gateway.enabled', false)
            ->where('gateway.available', false)
            ->has('prompts', 5)
            ->where('prompts.0.key', 'incident_root_cause')
            ->where('features.forecasting_enabled', false)
            ->has('inventory.signals')
            ->has('reviews.rule')
            ->has('runs.data'));
    expect($response->getContent())->not->toContain('sk-should-never-appear');
});

test('forecast snapshots are evaluated against actuals once the month has passed', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $admin = govUser([IntelligencePermissions::VIEW]);
    $admin->assignRole('Super Admin');
    $context = app(SystemContext::class)->cityWide($admin);
    expect($context)->not->toBeNull();

    // Eight complete months of complaints, two a month, so last month's actual is 2. The acting account
    // must hold the feedback target grants itself: visibility checks them directly, not through Gate::before.
    $scenario = SituationRoomScenario::build();
    $admin->givePermissionTo(Permission::findOrCreate(PublicFeedbackVisibility::EMPLOYEE_TARGET, 'web'), Permission::findOrCreate(PublicFeedbackVisibility::ORGANIZATION_TARGET, 'web'));
    $start = CarbonImmutable::now()->startOfMonth();
    for ($m = 8; $m >= 1; $m--) {
        foreach ([3, 9] as $day) {
            $scenario->submission($scenario->org['woreda_a1'], 'complaint', ['submitted_at' => $start->subMonthsNoOverflow($m)->addDays($day)]);
        }
    }

    $service = app(ForecastSnapshotService::class);
    $lastMonth = CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m');
    $nextMonth = CarbonImmutable::now()->addMonthNoOverflow()->format('Y-m');
    foreach ([$lastMonth, $nextMonth] as $period) {
        IntelligenceForecast::query()->create([
            'snapshot_id' => 'snap-1', 'metric' => 'complaints_monthly', 'scope_key' => 'all', 'period' => $period, 'value' => 4, 'lower' => 1, 'upper' => 7,
            'method' => 'linear', 'model_version' => ForecastSnapshotService::MODEL_VERSION, 'confidence' => 'low', 'points' => 6, 'data_through' => $lastMonth,
            'generated_at' => now()->subMonth(), 'created_at' => now()->subMonth(),
        ]);
    }

    expect($service->evaluate($context)['evaluated'])->toBe(1);
    $evaluated = IntelligenceForecast::query()->where('period', $lastMonth)->firstOrFail();
    expect((float) $evaluated->actual)->toBe(2.0)->and((float) $evaluated->abs_error)->toBe(2.0)->and((float) $evaluated->pct_error)->toBe(100.0)
        ->and(IntelligenceForecast::query()->where('period', $nextMonth)->firstOrFail()->actual)->toBeNull();

    $quality = $service->quality();
    expect($quality['complaints_monthly']['evaluated'])->toBe(1)->and($quality['complaints_monthly']['mae'])->toBe(2.0)->and($quality['complaints_monthly']['mape'])->toBe(100.0)
        ->and($quality['vacancies_monthly']['evaluated'])->toBe(0);

    // A snapshot run writes three points per metric with history (complaints: eight months; vacancies: twelve reconstructed) and never touches earlier rows.
    $result = $service->snapshot($context);
    $complaints = IntelligenceForecast::query()->where('snapshot_id', $result['snapshot_id'])->where('metric', 'complaints_monthly')->orderBy('period')->get();
    expect($result['written'])->toBe(IntelligenceForecast::query()->where('snapshot_id', $result['snapshot_id'])->count())
        ->and($complaints)->toHaveCount(3)
        ->and($complaints->first()->period)->toBe($start->format('Y-m'))
        ->and($complaints->first()->model_version)->toBe(ForecastSnapshotService::MODEL_VERSION)
        ->and($complaints->first()->actual)->toBeNull()
        ->and(IntelligenceForecast::query()->where('snapshot_id', 'snap-1')->count())->toBe(2);
});
