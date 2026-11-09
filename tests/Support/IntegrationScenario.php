<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\HierarchyVersionStatus;
use App\Models\ApiEndpointDefinition;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\WebhookSubscription;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\Integration\Events\OutboxPublisher;
use App\Services\Integration\Webhooks\SubscriptionService;
use Illuminate\Support\Str;

/**
 * Synthetic fixtures for the event integration layer: a bureau with a woreda
 * below it in a published hierarchy, an unrelated bureau, employees with
 * cards, and partner applications. No real data.
 */
final class IntegrationScenario
{
    public Organization $bureau;

    public Organization $woreda;

    public Organization $other;

    public Employee $woredaEmployee;

    public Employee $otherEmployee;

    public IdCard $woredaCard;

    public static function build(): self
    {
        config([
            'integration.events.enabled' => true,
            'integration.change_feed.enabled' => true,
            'integration.webhooks.enabled' => true,
        ]);
        app(ApiEndpointCatalogService::class)->sync();

        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'EVT-TYPE'], ['name_en' => 'Event Type']);
        $make = fn (string $code): Organization => Organization::query()->create(['organization_type_id' => $type->id, 'code' => $code, 'name_en' => 'Org '.$code, 'status' => 'active']);
        $s->bureau = $make('EVT-BUREAU');
        $s->woreda = $make('EVT-WOREDA');
        $s->other = $make('EVT-OTHER');

        $version = HierarchyVersion::query()->create([
            'version_name' => 'EVT v1', 'status' => HierarchyVersionStatus::Published->value,
            'approval_date' => now()->subMonth(), 'effective_from' => now()->subMonth()->toDateString(),
        ]);
        foreach ([[$s->bureau, $s->bureau, 0], [$s->woreda, $s->woreda, 0], [$s->bureau, $s->woreda, 1], [$s->other, $s->other, 0]] as [$a, $d, $depth]) {
            OrganizationClosurePath::query()->create(['hierarchy_version_id' => $version->id, 'ancestor_organization_id' => $a->id, 'descendant_organization_id' => $d->id, 'depth' => $depth]);
        }

        $s->woredaEmployee = self::employee($s->woreda, 'EVT-EMP-W');
        $s->otherEmployee = self::employee($s->other, 'EVT-EMP-O');
        $s->woredaCard = self::card($s->woredaEmployee, 'EVT-CARD-W');

        return $s;
    }

    public static function position(Organization $organization, string $code): Position
    {
        $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => $code.'-U', 'name_en' => 'Unit '.$code, 'unit_type' => 'department', 'status' => 'active']);
        $position = Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => $code, 'title_en' => 'Officer '.$code, 'is_active' => true]);
        PositionEstablishment::query()->create([
            'organization_id' => $organization->id, 'position_id' => $position->id, 'establishment_number' => 'EST-'.$code,
            'approved_slots' => 5, 'status' => EstablishmentStatus::Approved, 'effective_from' => now()->subYears(2)->toDateString(),
        ]);

        return $position;
    }

    public static function employee(Organization $organization, string $number): Employee
    {
        $position = self::position($organization, $number.'-P');
        $employee = Employee::query()->create([
            'employee_number' => $number, 'first_name' => 'Hana', 'last_name' => 'Girma', 'full_name' => 'Hana Girma', 'gender' => 'F',
            'national_id' => str_pad((string) crc32($number), 16, '9', STR_PAD_LEFT), 'phone' => '0911223344', 'status' => EmployeeStatus::Active->value,
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $organization->id, 'organization_unit_id' => $position->organization_unit_id,
            'position_id' => $position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        $employee->update(['current_assignment_id' => $assignment->id]);

        return $employee->fresh();
    }

    public static function card(Employee $employee, string $number): IdCard
    {
        $card = IdCard::query()->create([
            'employee_id' => $employee->id, 'card_number' => $number, 'status' => CardStatus::Active->value, 'is_current' => true,
            'issued_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        ]);
        app(CardQrPayloadService::class)->ensurePublicReference($card);

        return $card->refresh();
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $attributes
     * @return array{0: ExternalApplication, 1: string}
     */
    public static function application(array $scopes, array $attributes = []): array
    {
        $application = ExternalApplication::query()->create([
            'name' => 'Event Partner', 'code' => 'EVT-'.Str::upper(Str::random(6)), 'status' => 'active',
            'allowed_scopes' => $scopes, 'rate_limit_per_minute' => 1000, 'organization_access' => 'city', ...$attributes,
        ]);
        $ids = ApiEndpointDefinition::query()->whereIn('required_scope', $scopes)->pluck('id')->all();
        $application->endpoints()->sync(collect($ids)->mapWithKeys(fn (string $id): array => [$id => ['id' => (string) Str::uuid(), 'is_enabled' => true]])->all());

        return [$application, $application->createToken('evt', $scopes)->plainTextToken];
    }

    /** @param list<string> $types @return array{0: WebhookSubscription, 1: string} */
    public static function subscription(ExternalApplication $application, array $types, string $url = 'https://93.184.216.34/hooks/addis'): array
    {
        return app(SubscriptionService::class)->create($application, 'Partner hook', $url, $types, self::admin());
    }

    public static function admin(): User
    {
        return User::query()->where('email', 'integration-admin@example.test')->first()
            ?? User::factory()->create(['email' => 'integration-admin@example.test']);
    }

    public static function publish(): array
    {
        return app(OutboxPublisher::class)->publish();
    }

    /** @return list<string> */
    public static function types(?string $aggregateUuid = null): array
    {
        return IntegrationEvent::query()
            ->when($aggregateUuid !== null, fn ($q) => $q->where('aggregate_uuid', $aggregateUuid))
            ->orderBy('id')->pluck('event_type')->all();
    }
}
