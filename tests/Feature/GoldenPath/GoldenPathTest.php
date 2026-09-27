<?php

declare(strict_types=1);

use App\Enums\CardStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaSettlement;
use App\Models\CafeteriaTransaction;
use App\Models\CardRequest;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\IdCardPrintSnapshot;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CafeteriaScenario;

/*
 * The production flow, end to end, through the same HTTP routes people use
 * (docs/production-flow-map.md):
 *
 *   organization → unit → position → employee (+ assignment) → staff account
 *   and organization scope → card request → approval → print → issue →
 *   activate → public QR check → provider + network + main and branch
 *   cafeteria → organization access → service assignment → policy (maker /
 *   checker) → counter scan in the provider portal → transaction in both
 *   portals → organization liability → settlement → export.
 *
 * Codes come from code rules, as in production; nothing is inserted behind
 * the application's back except reference data (organization type, occupation).
 */
function goldenCodeRule(CodeRuleEntityType $type, string $prefix): void
{
    CodeRule::query()->create([
        'entity_type' => $type->value, 'scope_type' => null, 'scope_id' => null,
        'name_en' => $prefix.' code', 'prefix' => $prefix, 'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
        'separator' => '-', 'sequence_length' => 4, 'next_number' => 1, 'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y', 'is_active' => true, 'allow_manual_override' => false, 'require_approval_for_override' => true,
        'active_scope_key' => CodeRule::buildActiveScopeKey($type),
    ]);
}

beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    Storage::fake('local');
    Storage::fake('public');
    $this->travelTo(Carbon::parse('2026-09-18 09:00'));

    Role::findOrCreate('Super Admin', 'web');
    ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'is_active' => true]);
    $this->maker = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole('Super Admin');
    $this->checker = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole('Super Admin');

    foreach ([[CodeRuleEntityType::Organization, 'ORG'], [CodeRuleEntityType::Position, 'POS'], [CodeRuleEntityType::Employee, 'EMP']] as [$type, $prefix]) {
        goldenCodeRule($type, $prefix);
    }
    $this->type = OrganizationType::query()->create(['code' => 'bureau', 'name_en' => 'Bureau']);
    $this->occupation = (new Occupation)->forceFill(['code' => 'OCC-GOLD', 'name_en' => 'Officer']);
    $this->occupation->save();
});

/** Organization → unit → position → employee through the admin pages. */
function goldenEmployee(object $test, string $name): array
{
    $test->actingAs($test->maker)->post(route('organizations.store'), [
        'organization_type_id' => $test->type->id, 'name_en' => $name, 'status' => 'active',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $organization = Organization::query()->where('name_en', $name)->sole();

    $test->actingAs($test->maker)->post(route('organization-units.store'), [
        'organization_id' => $organization->id, 'unit_type' => 'department',
        'code' => 'U-'.Str::upper(Str::random(5)), 'name_en' => $name.' HR', 'status' => 'active',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $unit = OrganizationUnit::query()->where('organization_id', $organization->id)->sole();

    $test->actingAs($test->maker)->post(route('positions.store'), [
        'title_en' => 'HR Officer', 'is_active' => true, 'organization_unit_id' => $unit->id, 'occupation_id' => $test->occupation->id,
    ])->assertSessionHasNoErrors()->assertRedirect();
    $position = Position::query()->where('organization_unit_id', $unit->id)->sole();

    $test->actingAs($test->maker)->post(route('employees.store'), [
        'first_name' => 'Selam', 'last_name' => $name, 'status' => 'active',
        'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
        'effective_from' => '2026-09-01',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $employee = Employee::query()->where('last_name', $name)->sole();

    return [$organization, $unit, $position, $employee];
}

/** Card request → approval (second person) → print → issue → activate. */
function goldenActiveCard(object $test, Employee $employee): IdCard
{
    $test->actingAs($test->maker)->post(route('card-requests.store'), ['employee_id' => $employee->id, 'reason' => 'First card'])
        ->assertSessionHasNoErrors();
    $request = CardRequest::query()->where('employee_id', $employee->id)->sole();
    $test->actingAs($test->checker)->post(route('card-requests.approve', $request))->assertSessionHasNoErrors();
    $card = IdCard::query()->where('employee_id', $employee->id)->sole();
    expect($card->status)->toBe(CardStatus::PendingPrint);

    $test->actingAs($test->maker)->post(route('id-cards.prepare-print', $card))->assertRedirect();
    $snapshot = IdCardPrintSnapshot::query()->where('id_card_id', $card->id)->sole();
    $test->actingAs($test->maker)->post(route('id-cards.confirm-print', [$card, $snapshot]), ['printed_successfully' => '1'])->assertSessionHasNoErrors();
    expect($card->fresh()->status)->toBe(CardStatus::Printed);

    $test->actingAs($test->maker)->post(route('id-cards.issue', $card), ['issued_to' => $employee->full_name, 'received_by' => $employee->full_name])->assertSessionHasNoErrors();
    $test->actingAs($test->maker)->post(route('id-cards.activate', $card))->assertSessionHasNoErrors();

    return $card->fresh();
}

test('80 golden path: from a new organization to a settled, exported cafeteria meal', function (): void {
    // ── Structure, employee, account and scope ────────────────────────────
    [$organization, , $position, $employee] = goldenEmployee($this, 'Revenue Bureau');
    expect($organization->code)->toStartWith('ORG-2026-')
        ->and($position->job_position_code)->toStartWith('POS-2026-')
        ->and($employee->employee_number)->toStartWith('EMP-2026-')
        ->and($employee->currentAssignment->position_id)->toBe($position->id);

    $this->actingAs($this->maker)->post(route('users.store'), [
        'name' => 'Bureau HR Officer', 'email' => 'bureau.hr@example.test', 'password' => '', 'password_confirmation' => '', 'status' => 'active',
    ])->assertSessionHasNoErrors();
    $officer = User::query()->where('email', 'bureau.hr@example.test')->sole();
    $this->actingAs($this->maker)->post(route('users.organization-scopes.store', $officer), [
        'organization_id' => $organization->id, 'scope_type' => 'self',
    ])->assertSessionHasNoErrors();
    [$otherOrganization] = goldenEmployee($this, 'Health Bureau');
    $scope = app(OrganizationScopeService::class);
    expect($scope->canAccess($officer->fresh(), $organization->id))->toBeTrue()
        ->and($scope->canAccess($officer->fresh(), $otherOrganization->id))->toBeFalse();

    // ── ID card and public check ─────────────────────────────────────────
    $card = goldenActiveCard($this, $employee);
    expect($card->status)->toBe(CardStatus::Active)->and($card->is_current)->toBeTrue();
    $qr = app(CardQrPayloadService::class)->buildStableQrUrl($card);
    expect($qr)->toEndWith('/id-checker/'.$card->public_card_uuid);
    $this->get(route('id-checker.show', $card->public_card_uuid))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Public/IdChecker')->where('card.found', true));
    // The public page names nobody before the holder's one-time code.
    expect($this->get(route('id-checker.show', $card->public_card_uuid))->getContent())->not->toContain($employee->employee_number);

    // ── Provider, network, main and branch cafeteria ─────────────────────
    $this->actingAs($this->maker)->post(route('cafeteria.providers.store'), [
        'provider_mode' => 'new', 'provider_code' => 'prv-gold', 'provider_name_en' => 'Golden Catering',
        'location_type' => 'main', 'network_mode' => 'new', 'network_code' => 'net-gold', 'network_name_en' => 'Golden Network',
        'code' => 'CAF-MAIN', 'name_en' => 'Main Cafeteria', 'operational_status' => 'open', 'is_active' => true,
    ])->assertSessionHasNoErrors();
    $provider = Provider::query()->where('provider_code', 'PRV-GOLD')->sole();
    $main = CafeteriaProvider::query()->where('code', 'CAF-MAIN')->sole();
    $this->actingAs($this->maker)->post(route('cafeteria.providers.store'), [
        'provider_mode' => 'existing', 'provider_id' => $provider->id, 'location_type' => 'branch',
        'network_mode' => 'existing', 'cafeteria_service_network_id' => $main->cafeteria_service_network_id,
        'code' => 'CAF-BRANCH', 'name_en' => 'Branch Cafeteria', 'operational_status' => 'open', 'is_active' => true,
    ])->assertSessionHasNoErrors();
    $branch = CafeteriaProvider::query()->where('code', 'CAF-BRANCH')->sole();
    expect($branch->parent_cafeteria_id)->toBe($main->id);

    // ── Access, assignment, policy: each approved by a second person ─────
    $this->actingAs($this->maker)->post(route('cafeteria.access.store'), [
        'organization_id' => $organization->id, 'cafeteria_service_network_id' => $main->cafeteria_service_network_id,
        'primary_cafeteria_id' => $main->id, 'allow_cross_location_usage' => true, 'effective_from' => '2026-09-01',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->checker)->post(route('cafeteria.access.approve', OrganizationCafeteriaAccess::query()->sole()))->assertSessionHasNoErrors();
    $this->actingAs($this->maker)->post(route('cafeteria.assignments.store'), [
        'organization_id' => $organization->id, 'provider_id' => $provider->id,
        'cafeteria_service_network_id' => $main->cafeteria_service_network_id, 'effective_from' => '2026-09-01',
    ])->assertSessionHasNoErrors();
    $assignment = CafeteriaServiceAssignment::query()->sole();
    $this->actingAs($this->checker)->post(route('cafeteria.assignments.approve', $assignment))->assertSessionHasNoErrors();
    $this->actingAs($this->maker)->post(route('cafeteria.policies.store'), [
        'cafeteria_service_assignment_id' => $assignment->id,
        'daily_subsidy_amount' => '150.00', 'employee_contribution_amount' => '10.00', 'provider_price' => '160.00', 'currency_code' => 'ETB',
        'max_daily_uses' => 1, 'allow_advance_usage' => false, 'extra_scan_policy' => 'block',
        'monday_enabled' => true, 'tuesday_enabled' => true, 'wednesday_enabled' => true, 'thursday_enabled' => true, 'friday_enabled' => true,
        'exclude_public_holidays' => true, 'block_employee_leave' => true, 'effective_from' => '2026-09-21',
    ])->assertSessionHasNoErrors();
    $policy = CafeteriaServicePolicy::query()->sole();
    $this->actingAs($this->maker)->post(route('cafeteria.policies.submit', $policy))->assertSessionHasNoErrors();
    $this->actingAs($this->checker)->post(route('cafeteria.policies.approve', $policy))->assertSessionHasNoErrors();

    // ── Provider portal operator account ─────────────────────────────────
    $this->actingAs($this->maker)->post(route('provider-users.store'), [
        'provider_id' => $provider->id, 'name' => 'Counter Operator', 'email' => 'counter@golden.test', 'username' => 'golden.counter',
        'phone_number' => '0911 000 111', 'provider_role' => 'operator', 'portal_enabled' => true, 'status' => 'active',
        'service_permissions' => [], 'password' => '',
    ])->assertSessionHasNoErrors();
    $operator = ProviderUser::query()->where('username', 'golden.counter')->sole();
    // First sign-in replaces the temporary password (tests/Feature/Auth/ProviderPortalLoginTest.php).
    $operator->forceFill(['must_change_password' => false])->save();

    // ── Monday lunch: the counter scans the printed card ─────────────────
    $this->travelTo(Carbon::parse('2026-09-21 12:00'));
    $this->actingAs($operator, 'provider')->post(route('provider.portal.scan.store'), [
        'provider_id' => $main->id, 'qr_token' => $qr, 'scan_nonce' => (string) Str::uuid(), 'usage_mode' => 'single_day',
    ])->assertSessionHasNoErrors()->assertSessionHas('provider_scan_result.allowed', true);

    $transaction = CafeteriaTransaction::query()->sole();
    expect($transaction->employee_id)->toBe($employee->id)
        ->and($transaction->employee_organization_id)->toBe($organization->id)   // billed to the employee's organization
        ->and($transaction->cafeteria_provider_id)->toBe($main->id)              // where the meal was served
        ->and($transaction->provider_id)->toBe($provider->id)                    // who is paid
        ->and($transaction->cafeteria_service_policy_id)->toBe($policy->id)
        ->and((string) $transaction->subsidy_amount_applied)->toBe('150.00')
        ->and((string) $transaction->employee_contribution_applied)->toBe('10.00');

    // A second scan the same day is refused, not charged twice.
    $this->actingAs($operator, 'provider')->post(route('provider.portal.scan.store'), [
        'provider_id' => $branch->id, 'qr_token' => $qr, 'scan_nonce' => (string) Str::uuid(), 'usage_mode' => 'single_day',
    ])->assertSessionHas('provider_scan_result.allowed', false);
    expect(CafeteriaTransaction::query()->count())->toBe(1);

    // ── Both portals see it ──────────────────────────────────────────────
    // Explicit end date: on the SQLite test database a date cast is stored with
    // a time, so the default "to today" bound would exclude today's rows; MySQL
    // and PostgreSQL DATE columns do not (engine drift, see readiness report).
    $this->actingAs($operator, 'provider')->get(route('provider.portal.transactions.index', ['provider_id' => $main->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']))->assertOk()
        ->assertSee($transaction->transaction_number);
    $this->actingAs($this->checker)->get(route('cafeteria.transactions.show', $transaction))->assertOk();

    // ── Organization liability and settlement (maker drafts, checker finalizes) ─
    $period = ['provider_id' => $provider->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'];
    $this->actingAs($this->maker)->post(route('cafeteria.settlements.store'), $period)->assertSessionHasNoErrors()->assertRedirect();
    $settlement = CafeteriaSettlement::query()->sole();
    $this->actingAs($this->checker)->get(route('cafeteria.settlements.show', $settlement))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('byOrganization.0.employee_organization.code', $organization->code)
            ->where('byOrganization.0.transaction_count', 1)
            ->where('byOrganization.0.subsidy_amount', '150.00'));
    $this->actingAs($this->checker)->post(route('cafeteria.settlements.finalize', $settlement))->assertSessionHasNoErrors();
    expect($transaction->fresh()->cafeteria_settlement_id)->toBe($settlement->id);

    // ── Exports ──────────────────────────────────────────────────────────
    $statement = $this->actingAs($this->checker)->get(route('cafeteria.transactions.export', ['format' => 'print', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']));
    // Rendered as a (compressed) PDF: checked for being one; the CSV claim below is checked for content.
    $statement->assertOk();
    expect(substr((string) ($statement->getContent() ?: $statement->streamedContent()), 0, 5))->toBe('%PDF-');
    $claim = $this->actingAs($operator, 'provider')->get(route('provider.portal.transactions.export.csv', ['provider_id' => $main->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']));
    $claim->assertOk();
    expect($claim->streamedContent())->toContain($transaction->transaction_number);
});

test('82 negative paths on the golden setup: suspended card, no access, unknown card, scope', function (): void {
    [$organization, , , $employee] = goldenEmployee($this, 'Water Bureau');
    $card = goldenActiveCard($this, $employee);

    // Unknown card on the public checker.
    $this->get(route('id-checker.show', (string) Str::uuid()))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('card.found', false));

    // An organization with no cafeteria access is refused at the counter.
    $s = CafeteriaScenario::make();
    $scan = fn () => app(CafeteriaQrScanService::class)
        ->process(app(CardQrPayloadService::class)->buildStableQrUrl($card->fresh()), $s->main, Carbon::parse('2026-09-21 12:00'));
    expect($scan()['allowed'])->toBeFalse();

    // With access and policy but a suspended card, still refused.
    $s->enroll($organization, '120.00', $s->main);
    $card->forceFill(['status' => CardStatus::Suspended])->save();
    $result = $scan();
    expect($result['allowed'])->toBeFalse()
        ->and(CafeteriaTransaction::query()->count())->toBe(0);

    // A scoped officer cannot open another organization's employee.
    $officer = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
    $officer->givePermissionTo(Permission::findOrCreate('employees.view', 'web'));
    [$other, , , $stranger] = goldenEmployee($this, 'Transport Bureau');
    UserOrganizationScope::query()->create(['user_id' => $officer->id, 'organization_id' => $organization->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->actingAs($officer)->get(route('employees.show', $stranger))->assertForbidden();
});
