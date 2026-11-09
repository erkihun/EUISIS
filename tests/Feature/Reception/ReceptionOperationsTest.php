<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\RequireMfa;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Reception\Application\Services\ReceptionOperations;
use Modules\Reception\Domain\Models\ReceptionCounter;
use Modules\Reception\Domain\Models\ReceptionDesk;
use Modules\Reception\Domain\Models\ReceptionVisit;
use Modules\Reception\Domain\Models\ReceptionVisitor;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'REC-TYPE', 'name_en' => 'Bureau']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'REC-A', 'name_en' => 'Reception A', 'status' => OrganizationStatus::Active]);
    $this->otherOrganization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'REC-B', 'name_en' => 'Reception B', 'status' => OrganizationStatus::Active]);
    Role::findOrCreate('Reception Officer', 'web')->forceFill(['scope_type' => 'scoped'])->save();
    foreach (['reception.view', 'reception.operate', 'reception.configure'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->actor = User::factory()->create()->assignRole('Reception Officer');
    $this->actor->givePermissionTo(['reception.view', 'reception.operate', 'reception.configure']);
    UserOrganizationScope::query()->create(['user_id' => $this->actor->id, 'organization_id' => $this->organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true]);
    $this->operations = app(ReceptionOperations::class);
    $this->desk = $this->operations->createDesk(['organization_id' => $this->organization->id, 'name_en' => 'Main entrance', 'is_active' => true], $this->actor);
});

function receptionVisitPayload(Organization $organization, ReceptionDesk $desk, string $phone = '+251911000001'): array
{
    return ['visitor_name' => 'Test Visitor', 'phone' => $phone, 'visitor_category' => 'service_seeker', 'organization_id' => $organization->id, 'reception_desk_id' => $desk->id, 'purpose' => 'Service information'];
}

it('creates scoped desks through the configured code rule and writes an audit event', function (): void {
    expect($this->desk->code)->toMatch('/^REC-\d{4}$/')
        ->and($this->desk->organization_id)->toBe($this->organization->id);
    $this->assertDatabaseHas('audit_logs', ['event_type' => 'reception.configuration_changed', 'auditable_id' => $this->desk->id]);
});

it('reuses a visitor by privacy-preserving phone hash and never exposes the phone in serialization', function (): void {
    $first = $this->operations->registerVisit(receptionVisitPayload($this->organization, $this->desk), $this->actor);
    $second = $this->operations->registerVisit(receptionVisitPayload($this->organization, $this->desk), $this->actor);

    expect($first->visitor_id)->toBe($second->visitor_id)
        ->and(ReceptionVisitor::query()->count())->toBe(1)
        ->and($first->visitor->toArray())->not->toHaveKeys(['phone', 'phone_hash']);
});

it('issues unique daily queue numbers and enforces the queue state machine', function (): void {
    $one = $this->operations->registerVisit(receptionVisitPayload($this->organization, $this->desk, '+251911000011'), $this->actor);
    $two = $this->operations->registerVisit(receptionVisitPayload($this->organization, $this->desk, '+251911000012'), $this->actor);
    $first = $this->operations->issueTicket($one, [], $this->actor);
    $second = $this->operations->issueTicket($two, [], $this->actor);

    expect($first->queue_number)->toBe(1)->and($first->display_number)->toBe('001')
        ->and($second->queue_number)->toBe(2)->and($second->display_number)->toBe('002');

    $this->operations->transitionTicket($first, 'call', null, $this->actor);
    $this->operations->transitionTicket($first->refresh(), 'start', null, $this->actor);
    $completed = $this->operations->transitionTicket($first->refresh(), 'complete', null, $this->actor);
    expect($completed->status)->toBe('completed')->and(ReceptionVisit::query()->find($one->id)->status)->toBe('completed');
});

it('rejects duplicate queue tickets and cross-organization operations', function (): void {
    $visit = $this->operations->registerVisit(receptionVisitPayload($this->organization, $this->desk), $this->actor);
    $this->operations->issueTicket($visit, [], $this->actor);
    expect(fn () => $this->operations->issueTicket($visit->refresh(), [], $this->actor))->toThrow(ValidationException::class);

    expect(fn () => $this->operations->createDesk(['organization_id' => $this->otherOrganization->id, 'name_en' => 'Forbidden desk'], $this->actor))->toThrow(HttpException::class);
});

it('rejects a queue counter from a different reception desk', function (): void {
    $visit = $this->operations->registerVisit(receptionVisitPayload($this->organization, $this->desk), $this->actor);
    $otherDesk = ReceptionDesk::query()->create(['organization_id' => $this->organization->id, 'code' => 'REC-OTHER', 'name_en' => 'Other entrance', 'is_active' => true]);
    $otherCounter = ReceptionCounter::query()->create(['reception_desk_id' => $otherDesk->id, 'code' => 'OTHER-1', 'name_en' => 'Other counter', 'status' => 'open']);

    expect(fn () => $this->operations->issueTicket($visit, ['reception_counter_id' => $otherCounter->id], $this->actor))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('reception_queue_tickets', 0);
});

it('keeps scoped reception dashboard data isolated by institution', function (): void {
    $this->operations->registerVisit(receptionVisitPayload($this->organization, $this->desk), $this->actor);
    $otherDesk = ReceptionDesk::query()->create(['organization_id' => $this->otherOrganization->id, 'code' => 'OTHER-1', 'name_en' => 'Other', 'is_active' => true]);
    $otherVisitor = ReceptionVisitor::query()->create(['name' => 'Hidden Visitor', 'category' => 'service_seeker']);
    ReceptionVisit::query()->create(['reference' => 'VIS-HIDDEN', 'visitor_id' => $otherVisitor->id, 'organization_id' => $this->otherOrganization->id, 'reception_desk_id' => $otherDesk->id, 'reception_officer_id' => $this->actor->id, 'status' => 'checked_in', 'checked_in_at' => now()]);

    $response = $this->actingAs($this->actor)->withoutMiddleware([RequireMfa::class, EnsureAdminAccess::class])->get(route('reception.dashboard'));
    $response->assertOk()->assertInertia(fn ($page) => $page->component('Reception/Workspace')->where('stats.received', 1)->has('desks', 1));
});
