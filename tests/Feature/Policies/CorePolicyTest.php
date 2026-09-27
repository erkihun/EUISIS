<?php

declare(strict_types=1);

use App\Actions\IdCards\SubmitCardRequestAction;
use App\Enums\OrganizationScopeType;
use App\Models\CafeteriaProviderAssignment;
use App\Models\CafeteriaTransaction;
use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use App\Models\CardRequest;
use App\Models\ProviderUser;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Cafeteria\CafeteriaQrScanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CafeteriaScenario;

/*
 * Record-level authorization on the go-live path: a permission alone is never
 * enough — the record must also sit in the user's organization or cafeteria.
 */
beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    Role::findOrCreate('Super Admin', 'web');
    $this->travelTo(Carbon::parse('2026-09-21 12:00'));
    $this->s = CafeteriaScenario::make('A');
    $this->orgA = $this->s->organization('Organization A');
    $this->orgB = $this->s->organization('Organization B');
    $this->s->enroll($this->orgA, '120.00', $this->s->main);
    $this->s->enroll($this->orgB, '120.00', $this->s->main);
});

/** @param list<string> $permissions */
function policyUser(array $permissions, array $scopes = []): User
{
    $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    foreach ($scopes as $organizationId) {
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organizationId, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true]);
    }

    return $user->fresh();
}

it('lets card officers act on card requests only for employees in their organizations', function (): void {
    [$inA] = $this->s->employee($this->orgA);
    [$inB] = $this->s->employee($this->orgB);
    $requester = policyUser(['id-cards.submitRequest']);
    $requestA = app(SubmitCardRequestAction::class)->execute($inA, $requester);
    $requestB = app(SubmitCardRequestAction::class)->execute($inB, $requester);

    $approver = policyUser(['id-cards.view', 'id-cards.approveRequest', 'id-cards.rejectRequest'], [$this->orgA->id]);
    $viewer = policyUser(['id-cards.view'], [$this->orgA->id]);

    expect(Gate::forUser($approver)->allows('view', $requestA))->toBeTrue()
        ->and(Gate::forUser($approver)->allows('approve', $requestA))->toBeTrue()
        ->and(Gate::forUser($approver)->allows('approve', $requestB))->toBeFalse()   // other organization
        ->and(Gate::forUser($approver)->allows('reject', $requestB))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('approve', $requestA))->toBeFalse()     // no permission
        ->and(Gate::forUser($viewer)->allows('create', CardRequest::class))->toBeFalse();
});

it('lets a scoped printer handle a print batch only when every card in it is theirs', function (): void {
    [, $cardA] = $this->s->employee($this->orgA);
    [, $cardB] = $this->s->employee($this->orgB);
    $batch = fn (array $cards) => tap(CardPrintBatch::query()->create(['batch_number' => 'PB-'.uniqid(), 'status' => 'draft', 'total_cards' => count($cards), 'created_by' => User::factory()->create()->id]),
        fn ($b) => collect($cards)->each(fn ($card) => CardPrintBatchItem::query()->create(['card_print_batch_id' => $b->id, 'id_card_id' => $card->id, 'status' => 'pending'])));
    $printer = policyUser(['id-cards.createPrintBatch', 'id-cards.print'], [$this->orgA->id]);
    $mixed = $batch([$cardA, $cardB]);
    $own = $batch([$cardA]);

    expect(Gate::forUser($printer)->allows('view', $own))->toBeTrue()
        ->and(Gate::forUser($printer)->allows('markPrinted', $own))->toBeTrue()
        ->and(Gate::forUser($printer)->allows('view', $mixed))->toBeFalse()
        ->and(Gate::forUser($printer)->allows('markPrinted', $mixed))->toBeFalse()
        ->and(Gate::forUser(policyUser(['id-cards.print']))->allows('markPrinted', $mixed))->toBeTrue(); // unscoped staff
});

it('ties cafeteria transaction view, reversal and export to the user\'s assigned cafeteria', function (): void {
    [, $card] = $this->s->employee($this->orgA);
    app(CafeteriaQrScanService::class)->process($card, $this->s->main, now());
    $transaction = CafeteriaTransaction::query()->sole();
    $elsewhere = CafeteriaScenario::make('B')->main;

    $assigned = policyUser(['cafeteria_transactions.view', 'cafeteria_transactions.reverse']);
    CafeteriaProviderAssignment::query()->create(['cafeteria_provider_id' => $this->s->main->id, 'user_id' => $assigned->id, 'role' => 'viewer', 'is_active' => true]);
    $unassigned = policyUser(['cafeteria_transactions.view', 'cafeteria_transactions.reverse']);
    $elsewhereStaff = policyUser(['cafeteria_transactions.view']);
    CafeteriaProviderAssignment::query()->create(['cafeteria_provider_id' => $elsewhere->id, 'user_id' => $elsewhereStaff->id, 'role' => 'viewer', 'is_active' => true]);

    expect(Gate::forUser($assigned->fresh())->allows('view', $transaction))->toBeTrue()
        ->and(Gate::forUser($assigned->fresh())->allows('reverse', $transaction))->toBeTrue()
        ->and(Gate::forUser($unassigned)->allows('view', $transaction))->toBeFalse()
        ->and(Gate::forUser($unassigned)->allows('reverse', $transaction))->toBeFalse()
        ->and(Gate::forUser($elsewhereStaff->fresh())->allows('view', $transaction))->toBeFalse()
        // Export needs the list permission and an export permission.
        ->and(Gate::forUser($assigned->fresh())->allows('export', CafeteriaTransaction::class))->toBeFalse()
        ->and(Gate::forUser(policyUser(['cafeteria_transactions.view', 'cafeteria_reports.export']))->allows('export', CafeteriaTransaction::class))->toBeTrue();
});

it('shows staff without an assigned cafeteria no cafeteria figures, not every cafeteria\'s', function (): void {
    [, $card] = $this->s->employee($this->orgA);
    app(CafeteriaQrScanService::class)->process($card, $this->s->main, now());

    // Previously "no assignment" was read as "all cafeterias" in these lists.
    $this->actingAs(policyUser(['cafeteria_transactions.view']))->get(route('cafeteria.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.today_transactions', 0)->where('stats.active_providers', 0));
    $this->actingAs(policyUser(['cafeteria_transactions.view', 'cafeteria_providers.viewAll']))->get(route('cafeteria.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.today_transactions', 1));
});

it('lets a provider operator export any of their own provider\'s cafeterias, main or branch, and no other', function (): void {
    $operator = ProviderUser::query()->create([
        'provider_id' => $this->s->provider->id, 'name' => 'Operator', 'email' => 'op@policy.test', 'username' => 'policy.op',
        'password' => Hash::make('password'), 'provider_role' => 'operator', 'status' => 'active', 'portal_enabled' => true,
    ]);
    $other = CafeteriaScenario::make('C')->main;

    expect(Gate::forUser($operator)->allows('exportProviderTransactions', [CafeteriaTransaction::class, $this->s->main]))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('exportProviderTransactions', [CafeteriaTransaction::class, $this->s->branch]))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('exportProviderTransactions', [CafeteriaTransaction::class, $other]))->toBeFalse();

    $operator->forceFill(['status' => 'suspended'])->save();
    expect(Gate::forUser($operator->fresh())->allows('exportProviderTransactions', [CafeteriaTransaction::class, $this->s->main]))->toBeFalse();
});
