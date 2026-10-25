<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\CardEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P2 (ADR 0009): one card eligibility rule set, for a single decision and for the picker. */
class CoreCardEligibilityTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    private CardEligibility $eligibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
        $this->eligibility = app(CardEligibility::class);
    }

    private function reader(?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => 'Reader '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password', 'role' => 'core_officer',
            'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    private function codes(Employee $employee, ?string $type = 'new'): array
    {
        return array_keys($this->eligibility->decide($employee->fresh(), $type));
    }

    public function test_an_approved_active_placed_employee_with_a_verified_photo_and_no_card_is_eligible(): void
    {
        $person = $this->placedEmployee('EMP-0100', $this->positionB, '2024-01-01');

        $this->assertSame([], $this->codes($person));
        $picker = $this->eligibility->eligibleEmployees($this->reader())->keyBy('id');
        $this->assertSame(['new'], $picker[$person->id]['eligible_request_types']);
        // The employee with an active card may ask for a renewal or a correction, not a new card.
        $this->assertSame(['renewal', 'correction'], $picker[$this->employee->id]['eligible_request_types']);
        $this->assertSame(['ACTIVE_CARD_EXISTS'], $this->codes($this->employee));
    }

    public function test_every_reason_is_named_with_its_code(): void
    {
        $person = $this->placedEmployee('EMP-0101', $this->positionB, '2024-01-01');

        foreach ([Employee::DRAFT, Employee::SUBMITTED, Employee::VERIFIED] as $status) {
            $person->forceFill(['status' => $status])->save();
            $this->assertContains('EMPLOYEE_NOT_APPROVED', $this->codes($person), $status);
        }
        $person->forceFill(['status' => 'suspended'])->save();
        $this->assertContains('EMPLOYEE_NOT_ACTIVE', $this->codes($person));
        $person->forceFill(['status' => Employee::ACTIVE])->save();

        $this->bureauB->update(['status' => 'inactive']);
        $this->assertContains('ORGANIZATION_INACTIVE', $this->codes($person));
        $this->bureauB->update(['status' => 'active']);
        $this->positionB->update(['is_active' => false]);
        $this->assertContains('POSITION_INACTIVE', $this->codes($person));
        $this->positionB->update(['is_active' => true]);
        $person->currentAssignment->update(['effective_to' => now()->subDay()->toDateString()]);
        $this->assertContains('NO_ACTIVE_ASSIGNMENT', $this->codes($person));
        $person->currentAssignment->update(['effective_to' => null]);

        $photo = EmployeeDocument::query()->where('employee_id', $person->id)->sole();
        $photo->forceFill(['status' => EmployeeDocument::PENDING, 'verified_by' => null, 'verified_at' => null])->save();
        $this->assertSame(['PHOTO_NOT_VERIFIED'], $this->codes($person));
        $photo->forceFill(['status' => EmployeeDocument::ARCHIVED, 'is_current' => false])->save();
        $this->assertSame(['PHOTO_REQUIRED'], $this->codes($person));
        $this->giveVerifiedPhoto($person->id);

        CardRequest::query()->create(['employee_id' => $person->id, 'requested_by' => 1, 'status' => CardRequest::SUBMITTED, 'request_type' => 'new', 'submitted_at' => now()]);
        $this->assertSame(['PENDING_CARD_REQUEST_EXISTS'], $this->codes($person));
        CardRequest::query()->where('employee_id', $person->id)->update(['status' => CardRequest::CANCELLED]);

        DB::table('employee_duplicate_reviews')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $person->id, 'matched_employee_id' => $this->employee->id,
            'match_level' => 'strong_match', 'decision' => 'confirmed_duplicate', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['DUPLICATE_CONFIRMED'], $this->codes($person));
    }

    public function test_the_picker_and_the_single_decision_never_disagree(): void
    {
        $people = collect(range(1, 6))->map(fn (int $n): Employee => $this->placedEmployee('EMP-02'.$n, $n % 2 ? $this->positionA : $this->positionB, '2024-01-01'));
        $people[0]->forceFill(['status' => Employee::SUBMITTED])->save();
        EmployeeDocument::query()->where('employee_id', $people[1]->id)->delete();
        IdCard::query()->create(['employee_id' => $people[2]->id, 'card_number' => 'CARD-LOST', 'status' => IdCard::LOST, 'is_current' => true, 'public_card_uuid' => (string) Str::uuid()]);
        IdCard::query()->create(['employee_id' => $people[3]->id, 'card_number' => 'CARD-PRINT', 'status' => IdCard::PENDING_PRINT, 'is_current' => true, 'public_card_uuid' => (string) Str::uuid()]);

        $picker = $this->eligibility->eligibleEmployees($this->reader())->keyBy('id');
        foreach ([...$people, $this->employee] as $person) {
            $types = array_values(array_filter(['new', 'renewal', 'correction', 'lost', 'damaged', 'replacement'],
                fn (string $type): bool => $this->codes($person, $type) === []));
            $this->assertSame($types, $picker[$person->id]['eligible_request_types'] ?? [], $person->employee_number);
        }
        $this->assertSame(['lost'], $picker[$people[2]->id]['eligible_request_types']);
        $this->assertFalse($picker->has($people[3]->id));
        $diagnostic = $this->eligibility->ineligibleEmployees($this->reader())->keyBy('id');
        $this->assertSame(['EMPLOYEE_NOT_APPROVED'], $diagnostic[$people[0]->id]['reasons']);
        $this->assertSame(['PHOTO_REQUIRED'], $diagnostic[$people[1]->id]['reasons']);
        $this->assertSame(['CARD_IN_PRODUCTION'], $diagnostic[$people[3]->id]['reasons']);
    }

    public function test_the_picker_stays_inside_the_scope_and_runs_a_bounded_number_of_queries(): void
    {
        foreach (range(1, 40) as $n) {
            $this->placedEmployee('EMP-3'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), $n % 2 ? $this->positionA : $this->positionB, '2024-01-01');
        }
        $reader = $this->reader($this->bureauA);
        $this->eligibility->eligibleEmployees($reader); // warm the scope and settings caches

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->eligibility->eligibleEmployees($reader);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(21, $rows->count());
        $this->assertTrue($rows->every(fn (array $row): bool => $row['organization']['id'] === $this->bureauA->id));
        $this->assertLessThanOrEqual(2, $queries, 'The picker must not run a query per employee.');

        $this->get('/card-requests/create')->assertOk()->assertInertia(fn ($page) => $page->component('CardRequests/Create')
            ->where('employees', fn ($employees) => collect($employees)->every(fn ($row) => $row['current_assignment']['organization']['id'] === $this->bureauA->id)));
    }

    public function test_the_database_refuses_a_second_waiting_request_for_one_employee(): void
    {
        $open = ['employee_id' => $this->employee->id, 'requested_by' => 1, 'status' => CardRequest::SUBMITTED, 'request_type' => 'renewal', 'submitted_at' => now()];
        CardRequest::query()->create($open);
        CardRequest::query()->create(['status' => CardRequest::REJECTED] + $open);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CardRequest::query()->create(['status' => 'verified'] + $open);
    }

    public function test_a_normal_card_needs_a_verified_photo_from_request_to_approval(): void
    {
        $person = $this->placedEmployee('EMP-0400', $this->positionB, '2024-01-01');
        $photo = EmployeeDocument::query()->where('employee_id', $person->id)->sole();
        $photo->forceFill(['status' => EmployeeDocument::PENDING, 'verified_by' => null, 'verified_at' => null])->save();
        $clerk = $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/employees/'.$person->id.'/card-requests', ['request_type' => 'new'])->assertUnprocessable();
        $photo->forceFill(['status' => EmployeeDocument::VERIFIED, 'verified_by' => 2, 'verified_at' => now()])->save();
        $requestId = $this->postJson('/api/v1/employees/'.$person->id.'/card-requests', ['request_type' => 'new'])->assertCreated()->json('data.id');

        // The photo is withdrawn before approval: the approval is refused too.
        $photo->forceFill(['status' => EmployeeDocument::ARCHIVED, 'is_current' => false])->save();
        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/card-requests/'.$requestId.'/approve')->assertUnprocessable();
        $this->assertNotSame($clerk->id, 0);
    }
}
