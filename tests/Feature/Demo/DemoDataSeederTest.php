<?php

declare(strict_types=1);

use App\Enums\CafeteriaPolicyStatus;
use App\Enums\CardStatus;
use App\Enums\DailyActivityStatus;
use App\Enums\GrievanceStatus;
use App\Enums\HierarchyVersionStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceRoute;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\ProviderUser;
use App\Models\StrategicGoal;
use App\Models\User;
use App\Services\Cafeteria\Policy\CafeteriaEntitlementService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyResolver;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\Demo\DemoDataset;
use App\Support\Demo\DemoDataValidator;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * The development / QA / UAT demo dataset (docs/demo-seed-data.md).
 * Each test seeds a fresh database once; assertions are grouped to keep the
 * suite fast.
 */

beforeEach(function (): void {
    // Card printing writes artwork to the local disk.
    Storage::fake('local');
    Storage::fake('public');
});

function demoCounts(): array
{
    return [
        'organizations' => Organization::withTrashed()->count(),
        'units' => OrganizationUnit::withTrashed()->count(),
        'positions' => Position::withTrashed()->count(),
        'employees' => Employee::query()->count(),
        'users' => User::query()->count(),
        'provider_users' => ProviderUser::query()->count(),
        'cards' => IdCard::query()->count(),
        'cafeterias' => CafeteriaProvider::withTrashed()->count(),
        'policies' => CafeteriaServicePolicy::query()->count(),
        'transactions' => CafeteriaTransaction::query()->count(),
    ];
}

test('01 seeds the full dataset, passes every check, and a second run changes nothing', function (): void {
    // A record that is not demo data must survive both runs untouched.
    $custom = Organization::query()->create([
        'organization_type_id' => OrganizationType::query()->create(['code' => 'CUSTOM', 'name_en' => 'Custom'])->id,
        'code' => 'CUSTOM-001', 'name_en' => 'Customer-created Organization', 'status' => 'active',
    ]);

    $this->seed(DemoDataSeeder::class);
    $first = demoCounts();

    $validator = app(DemoDataValidator::class);
    expect(collect($validator->validate())->reject(fn (array $r) => $r['passed'])->values()->all())->toBe([]);
    expect($validator->counts())->toMatchArray([
        'Organizations' => 5, 'Support organizations' => 1, 'Organization Units' => 32, 'Positions' => 38,
        'Employees' => 32, 'Users' => 15, 'Provider users' => 4, 'ID Cards' => 32, 'Providers' => 3,
        'Cafeteria Networks' => 5, 'Cafeterias' => 25, 'Cafeteria Policies' => 6, 'Transactions' => 27,
        'Daily Activity logs' => 4, 'Performance goals' => 3, 'Grievance cases' => 2,
    ]);

    // Second run: no new rows, no unique-constraint failure.
    $this->seed(DemoDataSeeder::class);
    expect(demoCounts())->toBe($first);
    expect(collect($validator->validate())->reject(fn (array $r) => $r['passed'])->values()->all())->toBe([]);

    $custom->refresh();
    expect([$custom->code, $custom->name_en, $custom->status->value])->toBe(['CUSTOM-001', 'Customer-created Organization', 'active']);

    $this->artisan('demo-data:validate')->assertSuccessful();
});

test('02 organizations, structure, positions and employees follow the code rules and occupancy rules', function (): void {
    $this->seed(DemoDataSeeder::class);
    $validator = app(DemoDataValidator::class);

    foreach (DemoDataset::businessOrganizationKeys() as $key) {
        $organization = DemoDataset::requireOrganization($key);
        expect($organization->code)->toStartWith('ORG-')
            ->and($organization->name_am)->not->toBeEmpty()
            ->and(OrganizationUnit::query()->where('organization_id', $organization->id)->count())->toBeGreaterThanOrEqual(5)
            ->and(Position::query()->where('organization_id', $organization->id)->count())->toBeGreaterThanOrEqual(5)
            ->and($validator->accessibleCafeterias($organization)->count())->toBeGreaterThanOrEqual(5);

        // At least one manager position is occupied in every organization.
        $manager = Position::query()->where('organization_id', $organization->id)->where('metadata->manager', true)->first();
        expect($manager)->not->toBeNull()
            ->and(Employee::query()->whereHas('currentAssignment', fn ($q) => $q->where('position_id', $manager->id))->exists())->toBeTrue();
    }

    // Codes come from the rules, not from the seeder.
    $employee = DemoDataset::requireEmployee('E-1-1');
    $assignment = $employee->currentAssignment;
    $organization = DemoDataset::requireOrganization('ORG-1');
    expect($employee->employee_number)->toStartWith('EMP-')
        ->and(Position::query()->find($assignment->position_id)->job_position_code)->toStartWith($organization->code.'/')
        ->and(OrganizationUnit::query()->find($assignment->organization_unit_id)->code)->toStartWith('UNIT-')
        // National ID encrypted at rest, looked up by hash; synthetic value.
        ->and($employee->getRawOriginal('national_id'))->not->toBe($employee->national_id)
        ->and($employee->national_id)->toStartWith('0000000000');

    // Organization 5 sits under Organization 4 in the published demo hierarchy.
    $version = HierarchyVersion::query()->where('version_name', DemoDataset::HIERARCHY_VERSION_NAME)->sole();
    expect($version->status)->toBe(HierarchyVersionStatus::Published);
});

test('03 cafeteria providers, networks, access, assignments and organization-specific policies are valid', function (): void {
    $this->seed(DemoDataSeeder::class);

    // Same provider serves more than one organization.
    $providerA = DemoDataset::provider('PRV-A');
    $served = CafeteriaServicePolicy::query()->where('provider_id', $providerA->id)->distinct()->pluck('organization_id');
    expect($served)->toHaveCount(2);

    // No global subsidy: every organization its own amount.
    $subsidies = collect(DemoDataset::businessOrganizationKeys())
        ->mapWithKeys(fn (string $key) => [$key => (string) app(DemoDataValidator::class)->activePolicy(DemoDataset::requireOrganization($key))->daily_subsidy_amount]);
    expect($subsidies->all())->toBe(['ORG-1' => '100.00', 'ORG-2' => '120.00', 'ORG-3' => '140.00', 'ORG-4' => '160.00', 'ORG-5' => '180.00']);

    // Each network: one main, the rest under it.
    $network = DemoDataset::network('A1');
    $main = DemoDataset::cafeteria('A1', 'MAIN');
    expect(CafeteriaProvider::query()->where('cafeteria_service_network_id', $network->id)->count())->toBe(5)
        ->and(CafeteriaProvider::query()->where('cafeteria_service_network_id', $network->id)->where('location_type', '!=', 'main')->pluck('parent_cafeteria_id')->unique()->all())->toBe([$main->id]);

    // Cross-location OFF grants only the primary and the explicit exception.
    $org2 = DemoDataset::requireOrganization('ORG-2');
    $a2 = collect(['MAIN', 'BR1', 'BR2', 'BR3', 'SP1'])->mapWithKeys(fn ($l) => [$l => DemoDataset::cafeteria('A2', $l)->id]);
    $allowed = app(DemoDataValidator::class)->accessibleCafeterias($org2);
    expect($allowed->contains($a2['MAIN']))->toBeTrue()
        ->and($allowed->contains($a2['BR1']))->toBeTrue()
        ->and($allowed->contains($a2['BR2']))->toBeFalse();
});

test('04 cross-location meal: Organization 2 staff at the main cafeteria keep Organization 2 as owner and policy', function (): void {
    $this->seed(DemoDataSeeder::class);

    $employee = DemoDataset::requireEmployee('E-2-2');
    $org2 = DemoDataset::requireOrganization('ORG-2');
    $main = DemoDataset::cafeteria('A1', 'MAIN');
    $transaction = CafeteriaTransaction::query()->where('employee_id', $employee->id)->where('cafeteria_provider_id', $main->id)->firstOrFail();
    $policy = CafeteriaServicePolicy::query()->find($transaction->cafeteria_service_policy_id);

    expect($transaction->employee_organization_id)->toBe($org2->id)          // financial owner
        ->and($policy->organization_id)->toBe($org2->id)                       // Organization 2's policy applies
        ->and((string) $transaction->subsidy_amount_applied)->toBe('120.00')   // not Organization 1's 100
        ->and($transaction->cafeteria_provider_id)->toBe($main->id)           // service location
        ->and($transaction->provider_id)->toBe(DemoDataset::provider('PRV-A')->id); // payee

    // The main cafeteria is also Organization 1's.
    expect(CafeteriaTransaction::query()->where('cafeteria_provider_id', $main->id)
        ->where('employee_organization_id', DemoDataset::requireOrganization('ORG-1')->id)->exists())->toBeTrue();
});

test('05 policy history: v1 superseded and v2 active without overlap, old meals keep the v1 snapshot', function (): void {
    $this->seed(DemoDataSeeder::class);

    $org5 = DemoDataset::requireOrganization('ORG-5');
    [$v1, $v2] = CafeteriaServicePolicy::query()->where('organization_id', $org5->id)->orderBy('version_no')->get()->all();

    expect($v1->policy_group_id)->toBe($v2->policy_group_id)
        ->and($v1->status)->toBe(CafeteriaPolicyStatus::Superseded)
        ->and($v2->status)->toBe(CafeteriaPolicyStatus::Active)
        ->and($v2->supersedes_policy_id)->toBe($v1->id)
        ->and($v1->effective_to->toDateString())->toBe($v2->effective_from->copy()->subDay()->toDateString());

    // Today resolves to v2.
    $employee = DemoDataset::requireEmployee('E-5-2');
    $resolution = app(CafeteriaPolicyResolver::class)->resolve($employee, DemoDataset::cafeteria('C1', 'MAIN'), today()->setTime(12, 0));
    expect($resolution->policy?->id)->toBe($v2->id);

    $meals = CafeteriaTransaction::query()->where('employee_id', $employee->id)->get();
    $old = $meals->filter(fn ($t) => $t->cafeteria_service_policy_id === $v1->id);
    $new = $meals->filter(fn ($t) => $t->cafeteria_service_policy_id === $v2->id);
    expect($old)->toHaveCount(2)->and($new)->toHaveCount(2)
        ->and($old->map(fn ($t) => (string) $t->subsidy_amount_applied)->unique()->values()->all())->toBe(['170.00'])
        ->and($new->map(fn ($t) => (string) $t->subsidy_amount_applied)->unique()->values()->all())->toBe(['180.00']);
});

test('06 accounts, scopes, ID cards and leave support the security and workflow scenarios', function (): void {
    $this->seed(DemoDataSeeder::class);
    $scopes = app(OrganizationScopeService::class);

    $org1Employee = DemoDataset::requireEmployee('E-1-2');
    $org2Employee = DemoDataset::requireEmployee('E-2-2');
    $org5Employee = DemoDataset::requireEmployee('E-5-2');

    // Authorized vs unauthorized scope.
    $hr = DemoDataset::requireUser('demo.org1.hr@example.test');
    expect($scopes->canAccessEmployee($hr, $org1Employee))->toBeTrue()
        ->and($scopes->canAccessEmployee($hr, $org2Employee))->toBeFalse();

    // Subtree scope on Organization 4 reaches Organization 5, not Organization 1.
    $subtree = DemoDataset::requireUser('demo.org4.subtree.admin@example.test');
    expect($scopes->canAccessEmployee($subtree, $org5Employee))->toBeTrue()
        ->and($scopes->canAccessEmployee($subtree, $org1Employee))->toBeFalse();

    // Employee portal account linked to its own employee record.
    expect(DemoDataset::requireUser('demo.org1.employee@example.test')->employee?->id)->toBe(DemoDataset::requireEmployee('E-1-6')->id);

    // Provider users are separate accounts of their own provider.
    expect(ProviderUser::query()->where('email', 'demo.provider.a.operator@example.test')->sole()->provider_id)->toBe(DemoDataset::provider('PRV-A')->id)
        ->and(User::query()->where('email', 'like', 'demo.provider.%')->exists())->toBeFalse();

    // Card examples; every other employee holds one active card.
    $status = fn (string $key) => IdCard::query()->where('employee_id', DemoDataset::requireEmployee($key)->id)->sole();
    expect($status('E-2-6')->status)->toBe(CardStatus::Lost)
        ->and($status('E-3-6')->status)->toBe(CardStatus::Expired)
        ->and($status('E-4-6')->reprint_required)->toBeTrue()
        ->and(IdCard::query()->where('status', CardStatus::Active)->count())->toBe(30)
        ->and(IdCard::query()->pluck('token_hash')->unique()->count())->toBe(32);

    // The QR payload names nobody.
    $card = $status('E-1-2');
    $qr = app(CardQrPayloadService::class)->buildStableQrUrl($card);
    expect($qr)->toEndWith($card->public_card_uuid)->not->toContain($org1Employee->employee_number);

    // Approved leave covering the first run's day blocks the cafeteria.
    expect(app(CafeteriaEntitlementService::class)->isOnLeave(DemoDataset::requireEmployee(DemoDataset::LEAVE_EMPLOYEE), today()))->toBeTrue();
});

test('07 an existing published hierarchy stays published; the demo version is a draft that keeps its edges', function (): void {
    $type = OrganizationType::query()->create(['code' => 'CUSTOM', 'name_en' => 'Custom']);
    [$parent, $child] = collect(['REAL-ROOT', 'REAL-CHILD'])->map(fn (string $code) => Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => $code, 'name_en' => $code, 'status' => 'active',
    ]))->all();
    $published = HierarchyVersion::query()->create(['version_name' => 'Real v1', 'status' => HierarchyVersionStatus::Published, 'effective_from' => '2026-01-01']);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $published->id, 'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id, 'relationship_type' => 'reports_to', 'effective_from' => '2026-01-01']);

    $this->seed(DemoDataSeeder::class);

    $demo = HierarchyVersion::query()->where('version_name', DemoDataset::HIERARCHY_VERSION_NAME)->sole();
    expect($published->fresh()->status)->toBe(HierarchyVersionStatus::Published)
        ->and($demo->status)->toBe(HierarchyVersionStatus::Draft)
        ->and(OrganizationEdge::query()->where('hierarchy_version_id', $demo->id)->where('child_organization_id', $child->id)->exists())->toBeTrue()
        ->and(OrganizationEdge::query()->where('hierarchy_version_id', $demo->id)->count())->toBe(1 + 5)
        ->and(app(DemoDataValidator::class)->notes())->toHaveCount(1);
});

test('08 demo seeding is refused in production and is not part of DatabaseSeeder', function (): void {
    app()->detectEnvironment(fn () => 'production');

    // Invoked directly: db:seed would first ask for confirmation in production.
    expect(fn () => app(DemoDataSeeder::class)->setContainer(app())->__invoke())
        ->toThrow(RuntimeException::class, 'Demo data seeding is disabled in production.');
    expect(Organization::query()->where('metadata->demo_dataset', DemoDataset::TAG)->exists())->toBeFalse();

    expect(file_get_contents((new ReflectionClass(DatabaseSeeder::class))->getFileName()))->not->toContain('DemoDataSeeder');
});

test('09 grievance: approved committee of existing employees, approved routes, a submitted and an under-review case', function (): void {
    $this->seed(DemoDataSeeder::class);

    $organization = DemoDataset::requireOrganization('ORG-5');
    $committee = GrievanceCommittee::query()->where('organization_id', $organization->id)->sole();
    $members = $committee->members()->get();
    expect($committee->status)->toBe('active')
        ->and($committee->approved_by)->not->toBe($committee->created_by)
        ->and($members)->toHaveCount(3)
        ->and($members->pluck('role')->map(fn ($r) => $r instanceof BackedEnum ? $r->value : $r)->sort()->values()->all())->toBe(['chairperson', 'member', 'writer'])
        ->and(Employee::query()->whereIn('id', $members->pluck('employee_id'))->count())->toBe(3);

    expect(GrievanceRoute::query()->whereNotNull('approved_at')->count())->toBe(3);

    $cases = Grievance::query()->where('employee_id', DemoDataset::requireEmployee('E-5-6')->id)->get()->keyBy('subject');
    expect($cases['DEMO: request for a workplace ergonomics review']->status)->toBe(GrievanceStatus::Submitted)
        ->and($cases['DEMO: disagreement over overtime scheduling']->status)->toBe(GrievanceStatus::UnderReview)
        ->and($cases->pluck('reference_number')->filter(fn ($r) => str_starts_with($r, 'DRAFT-')))->toBeEmpty();
});

test('10 optional modules: daily activity states and EPMS goals totalling exactly 100%', function (): void {
    $this->seed(DemoDataSeeder::class);

    $status = fn (string $key) => DailyActivityLog::query()->where('employee_id', DemoDataset::requireEmployee($key)->id)->sole()->status;
    expect($status('E-1-6'))->toBe(DailyActivityStatus::Approved)
        ->and($status('E-1-2'))->toBe(DailyActivityStatus::Submitted)
        ->and($status('E-1-3'))->toBe(DailyActivityStatus::Draft);

    $organization = DemoDataset::requireOrganization('ORG-5');
    $goals = StrategicGoal::query()->where('organization_id', $organization->id)->with('allocations')->get();
    expect($goals->sum(fn ($g) => (float) $g->weight_percent))->toBe(100.0);
    foreach ($goals as $goal) {
        expect($goal->allocations->sum(fn ($a) => (float) $a->organization_contribution_percent))->toBe((float) $goal->weight_percent)
            ->and($goal->allocations->where('is_lead', true))->toHaveCount(1);
    }
    $shared = $goals->firstWhere('code', 'DEMO-SG-1');
    expect($shared->is_shared)->toBeTrue()
        ->and($shared->allocations->map(fn ($a) => (float) $a->organization_contribution_percent)->sort()->values()->all())->toBe([5.0, 10.0, 15.0]);
});

test('11 seeding sends no mail', function (): void {
    Mail::fake();

    $this->seed(DemoDataSeeder::class);

    Mail::assertNothingOutgoing();
});
