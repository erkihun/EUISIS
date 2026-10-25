<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use App\Services\DuplicateEmployees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** Employee numbering, the duplicate-identity check, card eligibility and card expiry. */
class CoreRegistrationAndCardRulesTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    /** @param array<string, mixed> $overrides */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sara', 'last_name' => 'Alemu', 'position_id' => $this->positionB->id, 'effective_from' => '2025-01-01',
        ], $overrides);
    }

    public function test_a_blank_employee_number_is_generated(): void
    {
        $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/employees', $this->registration())->assertCreated()->assertJsonPath('data.employee_number', 'EMP-000001');
    }

    public function test_a_possible_duplicate_stops_registration_until_a_reviewer_confirms_a_different_person(): void
    {
        $this->actingAsCore('core_officer', $this->bureauB);
        $person = $this->registration(['first_name' => 'Abebe', 'last_name' => 'EMP-0001']);

        $response = $this->postJson('/api/v1/employees', $person)->assertStatus(409)->assertJsonPath('code', 'POSSIBLE_DUPLICATE');
        // The match is in bureau A, outside this officer's organizations: number and name only.
        $this->assertSame(['employee_number' => 'EMP-0001', 'full_name' => 'Abebe EMP-0001', 'level' => DuplicateEmployees::POSSIBLE, 'reasons' => ['name'], 'in_scope' => false], $response->json('matches.0'));
        $this->assertSame(1, Employee::query()->count());

        $this->postJson('/api/v1/employees', $person + ['confirm_not_duplicate' => true])->assertUnprocessable()->assertJsonValidationErrors('duplicate_reason');
        $id = $this->postJson('/api/v1/employees', $person + ['confirm_not_duplicate' => true, 'duplicate_reason' => 'Different father and birth place on file'])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('employee_duplicate_reviews', ['employee_id' => $id, 'matched_employee_id' => $this->employee->id, 'decision' => 'distinct']);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'employee.duplicate_override', 'subject_id' => $id]);
    }

    public function test_the_same_name_and_birth_date_is_a_strong_match_and_shows_in_data_quality(): void
    {
        $this->employee->update(['date_of_birth' => '1990-05-01']);
        $this->actingAsCore('core_admin');

        $this->postJson('/api/v1/employees', $this->registration(['first_name' => 'Abebe', 'last_name' => 'EMP-0001', 'date_of_birth' => '1990-05-01']))
            ->assertStatus(409)->assertJsonPath('matches.0.level', DuplicateEmployees::STRONG)->assertJsonPath('matches.0.employee_id', $this->employee->id);

        // A twin record made outside the Core (e.g. imported) has no decision yet.
        $this->placedEmployee('EMP-0002', $this->positionB, '2024-01-01')->update(['first_name' => 'Abebe', 'last_name' => 'EMP-0001', 'full_name' => 'Abebe EMP-0001', 'date_of_birth' => '1990-05-01']);
        $checks = collect($this->getJson('/api/v1/data-quality')->assertOk()->json('checks'))->keyBy('key');
        $this->assertSame(2, $checks['possible_duplicate_employees']['count']);
    }

    public function test_a_national_id_already_registered_is_refused_and_never_stored_in_clear(): void
    {
        $this->employee->update(['national_id_hash' => DuplicateEmployees::nationalIdHash('1234 5678 9012')]);
        $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/employees', $this->registration(['national_id' => '123456789012']))
            ->assertUnprocessable()->assertJsonValidationErrors('national_id');

        $id = $this->postJson('/api/v1/employees', $this->registration(['national_id' => '999988887777']))->assertCreated()->json('data.id');
        $stored = Employee::query()->findOrFail($id);
        $this->assertNull($stored->national_id);
        $this->assertSame(DuplicateEmployees::nationalIdHash('999988887777'), $stored->national_id_hash);
        $this->getJson('/api/v1/employees/'.$id)->assertOk()->assertJsonMissingPath('data.employee.national_id_hash');
    }

    public function test_card_eligibility_names_every_reason(): void
    {
        $this->actingAsCore('core_officer');

        $this->getJson('/api/v1/employees/'.$this->employee->id.'/card-eligibility')->assertOk()
            ->assertJsonPath('eligible', false)->assertJsonStructure(['problems' => ['ACTIVE_CARD_EXISTS']]);
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'new'])->assertUnprocessable();

        $this->card->update(['status' => IdCard::REVOKED, 'is_current' => false]);
        $this->positionA->update(['is_active' => false]);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/card-eligibility')->assertOk()
            ->assertJsonPath('eligible', false)->assertJsonPath('problems.POSITION_INACTIVE', 'The employee\'s position is retired.');

        $this->positionA->update(['is_active' => true]);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/card-eligibility')->assertOk()->assertJsonPath('eligible', true);
    }

    public function test_the_photo_rule_is_on_by_default_and_can_be_switched_off(): void
    {
        $this->card->update(['status' => IdCard::REVOKED, 'is_current' => false]);
        EmployeeDocument::query()->where('employee_id', $this->employee->id)->delete();
        $this->actingAsCore('core_admin');

        $this->getJson('/api/v1/employees/'.$this->employee->id.'/card-eligibility')->assertJsonStructure(['problems' => ['PHOTO_REQUIRED']]);
        $this->putJson('/api/v1/settings/id_cards', ['values' => ['require_photo' => false]])->assertOk();
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/card-eligibility')->assertJsonPath('eligible', true);
    }

    public function test_cards_past_their_expiry_are_marked_expired_once_and_audited(): void
    {
        $this->card->update(['expires_at' => now()->subDay()]);
        $this->actingAsCore('core_admin');
        $checks = collect($this->getJson('/api/v1/data-quality')->json('checks'))->keyBy('key');
        $this->assertSame(1, $checks['expired_card_still_active']['count']);

        Artisan::call('core:expire-cards');
        Artisan::call('core:expire-cards');

        $this->assertSame(IdCard::EXPIRED, $this->card->fresh()->status);
        $this->assertSame(1, CoreAuditLog::query()->where('action', 'card.expired')->count());
        $this->postJson('/api/v1/cards/verify', ['card_uuid' => $this->card->public_card_uuid])->assertJsonPath('valid', false)->assertJsonPath('code', 'CARD_EXPIRED');
    }
}
