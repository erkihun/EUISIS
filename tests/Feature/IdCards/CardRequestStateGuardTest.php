<?php

declare(strict_types=1);

use App\Actions\IdCards\VerifyCardRequestDataAction;
use App\Enums\CardRequestStatus;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\User;
use App\Policies\CardRequestPolicy;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Verifying is the step after submission. It must never reopen a closed
 * request (approved, rejected, cancelled), and the verify, reject and cancel
 * actions are not offered once a request is past them.
 */

beforeEach(function (): void {
    foreach (['id-cards.verifyRequest', 'id-cards.rejectRequest', 'id-cards.submitRequest'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->employee = Employee::query()->create([
        'employee_number' => 'CRSG-1', 'first_name' => 'Abel', 'last_name' => 'Tesfaye', 'full_name' => 'Abel Tesfaye', 'status' => 'active',
    ]);
    $this->actor = User::factory()->create();
});

function crsgRequest(CardRequestStatus $status): CardRequest
{
    return CardRequest::query()->create([
        'employee_id' => test()->employee->id, 'requested_by' => User::factory()->create()->id,
        'request_type' => 'new', 'status' => $status->value, 'submitted_at' => now(),
    ]);
}

it('refuses to verify a request that is not submitted', function (CardRequestStatus $status): void {
    $request = crsgRequest($status);

    expect(fn () => app(VerifyCardRequestDataAction::class)->execute($request, $this->actor))
        ->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe($status);
})->with([
    'approved' => CardRequestStatus::Approved,
    'rejected' => CardRequestStatus::Rejected,
    'cancelled' => CardRequestStatus::Cancelled,
    'already verified' => CardRequestStatus::Verified,
]);

it('verifies a submitted request', function (): void {
    $request = crsgRequest(CardRequestStatus::Submitted);

    app(VerifyCardRequestDataAction::class)->execute($request, $this->actor, 'Checked');

    expect($request->fresh()->status)->toBe(CardRequestStatus::Verified);
});

it('offers verify, reject and cancel only while the request is open', function (): void {
    $this->actor->givePermissionTo(['id-cards.verifyRequest', 'id-cards.rejectRequest', 'id-cards.submitRequest']);
    $submitted = crsgRequest(CardRequestStatus::Submitted);
    $approved = crsgRequest(CardRequestStatus::Approved);

    $scope = Mockery::mock(OrganizationScopeService::class);
    $scope->shouldReceive('canAccessEmployee')->andReturnTrue();
    $real = new CardRequestPolicy($scope);

    expect($real->verify($this->actor, $submitted))->toBeTrue()
        ->and($real->verify($this->actor, $approved))->toBeFalse()
        ->and($real->reject($this->actor, $approved))->toBeFalse()
        ->and($real->cancel($this->actor, $approved))->toBeFalse()
        ->and($real->reject($this->actor, $submitted))->toBeTrue();
});

it('binds a Super Admin to the workflow state too', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $this->actor->assignRole('Super Admin');

    $submitted = crsgRequest(CardRequestStatus::Submitted);
    $approved = crsgRequest(CardRequestStatus::Approved);

    expect($this->actor->can('verify', $submitted))->toBeTrue()
        ->and($this->actor->can('verify', $approved))->toBeFalse()
        ->and($this->actor->can('reject', $approved))->toBeFalse()
        ->and($this->actor->can('approve', $approved))->toBeFalse();
});
