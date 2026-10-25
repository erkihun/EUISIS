<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IdCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

class CredentialIntegrityTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
        $this->actingAsCore('core_officer');
    }

    public function test_verification_requires_active_employment(): void
    {
        foreach (['draft', 'suspended', 'archived', 'pending_verification'] as $status) {
            $this->employee->update(['status' => $status]);
            $this->postJson('/api/v1/cards/verify', ['card_uuid' => $this->card->public_card_uuid])
                ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('code', 'EMPLOYEE_INACTIVE');
        }
    }

    public function test_credentials_require_an_effective_owned_assignment(): void
    {
        $assignment = $this->employee->currentAssignment;
        foreach ([
            ['effective_from' => today()->addDay()],
            ['effective_to' => today()->subDay()],
            ['is_current' => false],
            ['assignment_status' => 'closed'],
        ] as $invalid) {
            $assignment->update(array_merge(['effective_from' => '2024-01-01', 'effective_to' => null, 'is_current' => true, 'assignment_status' => 'active'], $invalid));
            $this->card->update(['status' => IdCard::ACTIVE]);
            $this->postJson('/api/v1/cards/verify', ['card_uuid' => $this->card->public_card_uuid])
                ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('code', 'ASSIGNMENT_INACTIVE');
            $this->card->update(['status' => IdCard::ISSUED]);
            $this->postJson('/api/v1/id-cards/'.$this->card->id.'/activate')->assertUnprocessable();
        }
    }

    public function test_card_approval_rejects_an_expired_assignment(): void
    {
        $this->card->update(['status' => IdCard::REVOKED, 'is_current' => false]);
        $requestId = $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'new'])->assertCreated()->json('data.id');
        $this->employee->currentAssignment->update(['effective_to' => today()->subDay()]);
        $this->actingAsCore('core_admin');
        $this->postJson('/api/v1/card-requests/'.$requestId.'/approve')->assertUnprocessable();
    }

    public function test_invalid_credentials_cannot_be_activated(): void
    {
        foreach ([['expires_at' => now()->subDay()], ['is_current' => false], ['qr_status' => 'revoked'], ['revoked_at' => now()]] as $invalid) {
            $this->card->update(array_merge(['status' => IdCard::ISSUED, 'is_current' => true, 'expires_at' => null, 'revoked_at' => null, 'qr_status' => 'active'], $invalid));
            $this->postJson('/api/v1/id-cards/'.$this->card->id.'/activate')->assertUnprocessable();
            $this->assertSame(IdCard::ISSUED, $this->card->fresh()->status);
        }
    }

    public function test_renewal_retires_a_naturally_expired_card_and_preserves_history(): void
    {
        $this->card->update(['expires_at' => now()->subDay()]);
        $requestId = $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'renewal'])
            ->assertCreated()->json('data.id');
        $this->actingAsCore('core_admin');
        $newId = $this->postJson('/api/v1/card-requests/'.$requestId.'/approve')->assertOk()->json('data.id');
        $this->assertFalse($this->card->fresh()->is_current);
        $this->assertSame(IdCard::REPLACED, $this->card->fresh()->status);
        $this->assertDatabaseHas('card_replacements', ['old_card_id' => $this->card->id, 'new_card_id' => $newId]);
    }

    public function test_renewal_requires_an_existing_card(): void
    {
        $this->card->update(['is_current' => false]);
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'renewal'])->assertUnprocessable();
    }

    public function test_renewal_does_not_replace_an_unexpired_card(): void
    {
        $this->card->update(['expires_at' => now()->addYear()]);
        $requestId = $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'renewal'])->assertCreated()->json('data.id');
        $this->actingAsCore('core_admin');
        $this->postJson('/api/v1/card-requests/'.$requestId.'/approve')->assertUnprocessable();
        $this->assertTrue($this->card->fresh()->isValidCredential());
    }
}
