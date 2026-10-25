<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** Organization scope = WHERE, on every read. */
class CoreScopeTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
        $this->placedEmployee('EMP-0002', $this->positionB, '2024-02-01');
    }

    public function test_a_city_wide_account_sees_every_employee(): void
    {
        $this->actingAsCore('core_viewer');

        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_a_bureau_account_sees_only_its_bureau(): void
    {
        $this->actingAsCore('core_viewer', $this->bureauA);

        $this->getJson('/api/v1/employees')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.employee_number', 'EMP-0001');
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/positions')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_a_parent_account_sees_the_organizations_under_it(): void
    {
        $this->actingAsCore('core_viewer', $this->city);

        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_search_ignores_letter_case(): void
    {
        $this->actingAsCore('core_viewer');

        $this->getJson('/api/v1/employees?search=abebe%20emp-0002')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.employee_number', 'EMP-0002');
    }

    public function test_an_employee_outside_the_scope_is_not_found(): void
    {
        $this->actingAsCore('core_viewer', $this->bureauB);

        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertNotFound();
    }

    public function test_employee_detail_hides_personal_fields(): void
    {
        $this->actingAsCore('core_viewer');

        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertOk()
            ->assertJsonPath('data.employee.employee_number', 'EMP-0001')
            ->assertJsonMissingPath('data.employee.national_id')
            ->assertJsonCount(1, 'data.cards');
    }

    public function test_card_verification_answers_minimally_and_within_scope(): void
    {
        $this->actingAsCore('core_viewer');
        $this->postJson('/api/v1/cards/verify', ['card_uuid' => $this->card->public_card_uuid])->assertOk()
            ->assertExactJson(['valid' => true, 'status' => 'active', 'employee_number' => 'EMP-0001', 'employee_name' => 'Abebe EMP-0001']);

        $this->actingAsCore('core_viewer', $this->bureauB);
        $this->postJson('/api/v1/cards/verify', ['card_uuid' => $this->card->public_card_uuid])->assertNotFound()->assertJsonPath('valid', false);
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $this->actingAsCore('core_admin', null, active: false);

        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_the_dashboard_counts_within_scope(): void
    {
        $this->actingAsCore('core_viewer', $this->bureauA);

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('metrics.organizations', 1)
            ->assertJsonPath('metrics.employees_placed', 1)
            ->assertJsonPath('metrics.cards_active', 1);
    }
}
