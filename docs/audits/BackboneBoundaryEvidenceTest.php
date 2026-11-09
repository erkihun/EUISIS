<?php

use App\Models\Employee;
use App\Models\ApiEndpointDefinition;
use App\Models\ExternalApplication;
use App\Models\IdCard;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Observed-behavior audit evidence, not desired security acceptance tests. */
final class BackboneBoundaryEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_ability_reads_directory_without_hr_permission(): void
    {
        $employee = $this->employee();
        $user = User::factory()->create();
        $token = $user->createToken('audit-provider', ['provider:access'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/employees/'.$employee->id)
            ->assertOk()->assertJsonPath('data.employee_number', 'AUDIT-SYNTHETIC-1');
    }

    public function test_disabled_qr_is_still_valid_in_partner_verification(): void
    {
        $employee = $this->employee();
        $card = IdCard::query()->create([
            'employee_id' => $employee->id,
            'card_number' => 'AUDIT-CARD-1',
            'status' => 'active',
            'is_current' => true,
            'public_card_uuid' => (string) Str::uuid(),
            'qr_status' => 'revoked',
            'expires_at' => now()->addYear(),
        ]);
        $token = User::factory()->create()->createToken('audit-verify', ['id_cards.verify'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$card->public_card_uuid);
        $response->assertOk()->assertJsonPath('valid', true);
        $this->assertArrayNotHasKey('employee_id', $response->json());
        $this->assertArrayNotHasKey('id', $response->json('employee'));
    }

    public function test_malformed_partner_card_uuid_has_no_validation_error_on_sqlite(): void
    {
        $token = User::factory()->create()->createToken('audit-verify', ['id_cards.verify'])->plainTextToken;
        // PostgreSQL casts this bound parameter to uuid and rejects it; SQLite
        // does not. This case documents why SQLite success cannot prove PG parity.
        $this->withToken($token)->getJson('/api/v1/id-cards/verify/not-a-uuid')->assertNotFound();
    }

    public function test_registered_partner_eligibility_fails_with_actor_type_error(): void
    {
        $employee = $this->employee();
        app(ApiEndpointCatalogService::class)->sync();
        $application = ExternalApplication::query()->create([
            'name' => 'Synthetic audit client',
            'code' => 'AUDIT-ONLY',
            'status' => 'active',
            'allowed_scopes' => ['service_eligibility.check'],
            'rate_limit_per_minute' => 60,
        ]);
        $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/employees/{employee}/service-eligibility')->firstOrFail();
        $application->endpoints()->attach($endpoint->id, ['id' => (string) Str::uuid(), 'is_enabled' => true]);
        $token = $application->createToken('audit', ['service_eligibility.check'])->plainTextToken;
        $this->withoutExceptionHandling();
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('must be of type ?App\\Models\\User');
        $this->withToken($token)->getJson('/api/v1/employees/'.$employee->id.'/service-eligibility?service_type=cafeteria');
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'AUDIT-SYNTHETIC-1',
            'first_name' => 'Synthetic',
            'last_name' => 'Audit',
            'full_name' => 'Synthetic Audit',
            'status' => 'active',
        ]);
    }
}
