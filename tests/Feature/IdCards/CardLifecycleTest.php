<?php

declare(strict_types=1);

use App\Actions\Entitlements\GrantEntitlementAction;
use App\Actions\IdCards\ActivateCardAction;
use App\Actions\IdCards\GenerateCardTokenAction;
use App\Actions\IdCards\IssueCardAction;
use App\Actions\IdCards\ReplaceCardAction;
use App\Actions\IdCards\RevokeCardAction;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\CardReplacement;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Verification\VerifyCardForServiceAction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['cards.manage', 'entitlements.view'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    Role::findOrCreate('HR Officer', 'web')->syncPermissions(Permission::all());
});

function lifecycleCard(CardStatus $status = CardStatus::Active): array
{
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-LC-'.uniqid(),
        'full_name' => 'Lifecycle Employee',
        'first_name' => 'Lifecycle',
        'last_name' => 'Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-LC-'.uniqid(),
        'status' => $status,
        'expires_at' => now()->addYear(),
        'token_version' => 0,
        'is_current' => true,
    ]);

    return [$employee, $card];
}

function verifyCard(IdCard $card, Employee $employee, string $rawToken): array
{
    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');

    $serviceType = ServiceType::query()->firstOrCreate(
        ['code' => 'lc-transport-'.uniqid()],
        ['name_en' => 'LC Transport']
    );
    $provider = ServiceProvider::query()->create([
        'code' => 'SP-LC-'.uniqid(),
        'service_type_id' => $serviceType->id,
        'name' => 'LC Provider',
        'status' => 'active',
    ]);
    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 10);

    return [
        app(VerifyCardForServiceAction::class)->execute(
            $card->id.'|'.$rawToken,
            $serviceType,
            $provider,
        ),
    ];
}

// Test 11: Lost card cannot verify
it('lost card verification returns denied', function (): void {
    [$employee, $card] = lifecycleCard(CardStatus::Lost);
    $rawToken = app(GenerateCardTokenAction::class)->execute($card);
    $card->refresh();

    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');

    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'lost-test'], ['name_en' => 'Lost Test']);
    $provider = ServiceProvider::query()->create([
        'code' => 'SP-LOST-'.uniqid(), 'service_type_id' => $serviceType->id,
        'name' => 'Lost Provider', 'status' => 'active',
    ]);
    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 10);

    $result = app(VerifyCardForServiceAction::class)->execute($card->id.'|'.$rawToken, $serviceType, $provider);
    expect($result['allowed'])->toBeFalse();
});

// Test 12: Damaged card can enter replacement flow
it('damaged card can be replaced', function (): void {
    [$employee, $card] = lifecycleCard(CardStatus::Damaged);
    $actor = User::factory()->create()->assignRole('HR Officer');

    $cardRequest = app(ReplaceCardAction::class)->execute($card, $actor, 'Card was damaged');

    expect($cardRequest)->not->toBeNull();
    expect($cardRequest->previous_card_id)->toBe($card->id);
});

// Test 13: Replacement links previous card
it('replacement card links to previous card', function (): void {
    [$employee, $card] = lifecycleCard(CardStatus::Lost);
    $actor = User::factory()->create()->assignRole('HR Officer');

    $cardRequest = app(ReplaceCardAction::class)->execute($card, $actor, 'Lost');

    expect($cardRequest->previous_card_id)->toBe($card->id);
    expect(CardReplacement::query()->where('old_card_id', $card->id)->exists())->toBeTrue();
});

// Test 14: Revoked card cannot verify
it('revoked card verification returns denied', function (): void {
    [$employee, $card] = lifecycleCard(CardStatus::Active);
    $rawToken = app(GenerateCardTokenAction::class)->execute($card);
    $card->refresh();

    $actor = User::factory()->create()->assignRole('HR Officer');
    app(RevokeCardAction::class)->execute($card->fresh(), $actor, 'Revocation test');

    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'revoke-test'], ['name_en' => 'Revoke Test']);
    $provider = ServiceProvider::query()->create([
        'code' => 'SP-REVOKE-'.uniqid(), 'service_type_id' => $serviceType->id,
        'name' => 'Revoke Provider', 'status' => 'active',
    ]);
    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 10);

    $result = app(VerifyCardForServiceAction::class)->execute($card->id.'|'.$rawToken, $serviceType, $provider);
    expect($result['allowed'])->toBeFalse()
        ->and($result['result_code'])->toBe('id_card_revoked');
});

// Test 15: Expired card cannot verify
it('expired card verification returns denied', function (): void {
    [$employee, $card] = lifecycleCard(CardStatus::Active);
    $card->update(['expires_at' => now()->subDay()]);
    $rawToken = app(GenerateCardTokenAction::class)->execute($card);
    $card->refresh();

    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'expired-test'], ['name_en' => 'Expired Test']);
    $provider = ServiceProvider::query()->create([
        'code' => 'SP-EXP-'.uniqid(), 'service_type_id' => $serviceType->id,
        'name' => 'Expired Provider', 'status' => 'active',
    ]);
    $actor = User::factory()->create()->assignRole('HR Officer');
    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 10);

    $result = app(VerifyCardForServiceAction::class)->execute($card->id.'|'.$rawToken, $serviceType, $provider);
    expect($result['allowed'])->toBeFalse()
        ->and($result['result_code'])->toBe('id_card_expired');
});

// Test 16: Card status flow: printed -> issued -> active
it('card activation requires issued status, not printed', function (): void {
    [$employee, $card] = lifecycleCard(CardStatus::Printed);
    $actor = User::factory()->create()->assignRole('HR Officer');

    expect(fn () => app(ActivateCardAction::class)->execute($card->fresh(), $actor))
        ->toThrow(DomainException::class, 'issued');

    $issued = app(IssueCardAction::class)->execute($card->fresh(), $actor);
    expect($issued->status)->toBe(CardStatus::Issued);

    $active = app(ActivateCardAction::class)->execute($issued->fresh(), $actor);
    expect($active->status)->toBe(CardStatus::Active);
});
