<?php

declare(strict_types=1);

use App\Enums\CardStatus;
use App\Enums\OrganizationScopeType;
use App\Models\CardPrintBatch;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\GrievanceScenario;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Permission::findOrCreate('id-cards.createPrintBatch', 'web');
    $type = OrganizationType::query()->firstOrCreate(['code' => 'PBT'], ['name_en' => 'Print batch test']);
    $this->own = GrievanceScenario::org($type, 'Own');
    $this->other = GrievanceScenario::org($type, 'Other');
    $this->ownCard = pendingCardFor(GrievanceScenario::employee($this->own, null));
    $this->otherCard = pendingCardFor(GrievanceScenario::employee($this->other, null));

    $this->officer = User::factory()->create();
    $this->officer->givePermissionTo('id-cards.createPrintBatch');
    UserOrganizationScope::query()->create([
        'user_id' => $this->officer->id, 'organization_id' => $this->own->id,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);
    $this->officer = $this->officer->fresh();
});

function pendingCardFor(Employee $employee): IdCard
{
    return IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-'.Str::upper(Str::random(8)),
        'status' => CardStatus::PendingPrint,
        'token_hash' => hash('sha256', Str::random(32)),
    ]);
}

test('the print queue shows a scoped officer only cards of their organizations, with display fields only', function (): void {
    actingAs($this->officer)
        ->get(route('print-batches.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingCards', 1)
            ->where('pendingCards.0.id', $this->ownCard->id)
            ->where('pendingTotal', 1)
            ->missing('pendingCards.0.employee.phone')
            ->missing('pendingCards.0.employee.date_of_birth'));
});

test('a scoped officer cannot batch a card outside their scope, and no batch is created', function (): void {
    actingAs($this->officer)
        ->post(route('print-batches.store'), ['card_ids' => [$this->ownCard->id, $this->otherCard->id]])
        ->assertSessionHasErrors('card_ids.1');

    expect(CardPrintBatch::query()->count())->toBe(0);
});
