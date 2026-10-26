<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataQualityIssue;
use App\Models\DigitalCredential;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P4/P5 (ADR 0011): the synthetic UAT dataset loads, covers every stage and is refused where it must be. */
class CoreUatSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_uat_dataset_covers_the_golden_path_states(): void
    {
        $this->artisan('core:seed-uat')->assertSuccessful();

        foreach ([Employee::DRAFT, Employee::SUBMITTED, Employee::VERIFIED, Employee::ACTIVE] as $status) {
            $this->assertTrue(Employee::query()->where('status', $status)->exists(), $status);
        }
        foreach ([IdCard::ACTIVE, IdCard::LOST, IdCard::REPLACED] as $status) {
            $this->assertTrue(IdCard::query()->where('status', $status)->exists(), $status);
        }
        $this->assertTrue(DigitalCredential::query()->where('status', 'revoked')->exists());
        $this->assertTrue(User::query()->where('scope_type', User::SCOPE_SUBTREE)->exists());
        $this->assertTrue(DataQualityIssue::query()->where('check_code', 'active_card_revoked_credential')->exists());
        $this->assertTrue(DataQualityIssue::query()->where('check_code', 'expired_card_still_active')->exists());
        $this->assertTrue(DataQualityIssue::query()->where('check_code', 'possible_duplicate_employees')->exists());
        $this->assertFalse(Employee::query()->where('email', 'like', '%@%')->where('email', 'not like', '%.test')->exists());

        // A second run on the same database is refused.
        $this->artisan('core:seed-uat')->assertFailed();
    }
}
