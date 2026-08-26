<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeFeedbackToken;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([
        'employees.view',
        'employees.viewAny',
        'cards.view',
        'service_feedback.settings.manage',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());

    // Holds employees.view but neither QR permission — the negative baseline.
    Role::findOrCreate('Employee Viewer', 'web')->syncPermissions(['employees.view']);

    $type = OrganizationType::query()->create(['code' => 'QR-TYPE', 'name_en' => 'QR Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'QR-ORG',
        'name_en' => 'QR Organization',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'QR-U1',
        'name_en' => 'QR Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'QR-P1',
        'title_en' => 'QR Officer',
        'is_active' => true,
    ]);

    // Sensitive values are real so the PII assertions below mean something.
    $this->employee = Employee::query()->create([
        'employee_number' => 'QR-EMP-1',
        'first_name' => 'Meron',
        'last_name' => 'Alemu',
        'full_name' => 'Meron Alemu',
        'national_id' => '5544332211009988',
        'phone' => '0913334444',
        'email' => 'meron.alemu@example.test',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

/** Give the employee an active card with a public reference. */
function giveActiveCard(Employee $employee): IdCard
{
    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'QR-CARD-0001',
        'status' => CardStatus::Active->value,
        'is_current' => true,
        'issued_at' => now()->subMonth(),
        'activated_at' => now()->subMonth(),
        'expires_at' => now()->addYear(),
    ]);

    app(CardQrPayloadService::class)->ensurePublicReference($card);

    return $card->fresh();
}

it('shows the feedback qr on the employee detail page', function (): void {
    $this->actingAs($this->admin)
        ->get(route('employees.show', $this->employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Show')
            ->where('qrCodes.canManageFeedbackQr', true)
            ->where('qrCodes.feedback.status', 'active')
        );

    $token = EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('employees.show', $this->employee->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('qrCodes.feedback.url', route('service-feedback.show', $token->token))
        );
});

it('shows the id verification qr when an active card exists', function (): void {
    $card = giveActiveCard($this->employee);

    $this->actingAs($this->admin)
        ->get(route('employees.show', $this->employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('qrCodes.canViewIdQr', true)
            ->where('qrCodes.idCard.url', route('id-checker.show', $card->public_card_uuid))
            ->where('qrCodes.idCard.card_number', 'QR-CARD-0001')
        );
});

it('reports no id card safely when the employee has none', function (): void {
    $this->actingAs($this->admin)
        ->get(route('employees.show', $this->employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Null, not an error: the page renders an explanatory empty state.
            ->where('qrCodes.canViewIdQr', true)
            ->where('qrCodes.idCard', null)
        );
});

it('renders a scannable qr image for both codes', function (): void {
    giveActiveCard($this->employee);

    $this->actingAs($this->admin)
        ->get(route('employees.show', $this->employee->id))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $qr = $page->toArray()['props']['qrCodes'];

            expect($qr['idCard']['qr_svg'])->toStartWith('data:image/svg+xml;base64,')
                ->and($qr['feedback']['qr_svg'])->toStartWith('data:image/svg+xml;base64,');
        });
});

it('keeps personal data out of both qr payloads', function (): void {
    giveActiveCard($this->employee);

    $this->actingAs($this->admin)
        ->get(route('employees.show', $this->employee->id))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $qr = $page->toArray()['props']['qrCodes'];

            foreach ([$qr['idCard']['url'], $qr['feedback']['url']] as $url) {
                expect($url)->not->toContain('Meron')
                    ->and($url)->not->toContain('Alemu')
                    ->and($url)->not->toContain('5544332211009988')
                    ->and($url)->not->toContain('0913334444')
                    ->and($url)->not->toContain('meron.alemu@example.test')
                    ->and($url)->not->toContain('QR-EMP-1');
            }
        });
});

it('points each qr at its own public page', function (): void {
    giveActiveCard($this->employee);

    $this->actingAs($this->admin)
        ->get(route('employees.show', $this->employee->id))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $qr = $page->toArray()['props']['qrCodes'];

            // The two flows must never be confusable with one another.
            expect($qr['idCard']['url'])->toContain('/id-checker/')
                ->and($qr['idCard']['url'])->not->toContain('/service-feedback/')
                ->and($qr['feedback']['url'])->toContain('/service-feedback/')
                ->and($qr['feedback']['url'])->not->toContain('/id-checker/');
        });
});

it('does not change either qr when the employee is updated', function (): void {
    $card = giveActiveCard($this->employee);

    $this->actingAs($this->admin)->get(route('employees.show', $this->employee->id))->assertOk();

    $tokenBefore = EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->firstOrFail()->token;
    $uuidBefore = $card->public_card_uuid;

    $this->employee->update([
        'first_name' => 'Renamed',
        'full_name' => 'Renamed Alemu',
        'phone' => '0911000222',
        'email' => 'renamed@example.test',
        'photo_path' => 'photos/updated.jpg',
        'status' => EmployeeStatus::Suspended->value,
    ]);

    // Reopening the page must not rotate anything either.
    $this->actingAs($this->admin)->get(route('employees.show', $this->employee->id))->assertOk();

    expect(EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and(EmployeeFeedbackToken::query()->firstOrFail()->token)->toBe($tokenBefore)
        ->and($card->fresh()->public_card_uuid)->toBe($uuidBefore);
});

it('does not mint a second token across repeated page views', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $this->actingAs($this->admin)->get(route('employees.show', $this->employee->id))->assertOk();
    }

    expect(EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('changes the feedback token only on an explicit regenerate', function (): void {
    $this->actingAs($this->admin)->get(route('employees.show', $this->employee->id))->assertOk();

    $before = EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->firstOrFail()->token;

    $this->actingAs($this->admin)
        ->post(route('employees.feedback-qr.regenerate', $this->employee->id))
        ->assertRedirect();

    $after = app(EmployeeFeedbackTokenService::class)->activeToken($this->employee->fresh())->token;

    expect($after)->not->toBe($before);

    // The retired code stops resolving for the public immediately.
    auth()->logout();

    $this->get('/service-feedback/'.$before)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
});

it('hides both qr cards from a user without the qr permissions', function (): void {
    giveActiveCard($this->employee);

    $viewer = User::factory()->create();
    $viewer->assignRole('Employee Viewer');

    $this->actingAs($viewer)
        ->get(route('employees.show', $this->employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('qrCodes.canViewIdQr', false)
            ->where('qrCodes.canManageFeedbackQr', false)
            // No payload at all, not merely a hidden card.
            ->where('qrCodes.idCard', null)
            ->where('qrCodes.feedback', null)
        );
});

it('does not provision a feedback token for an unauthorized viewer', function (): void {
    $viewer = User::factory()->create();
    $viewer->assignRole('Employee Viewer');

    $this->actingAs($viewer)->get(route('employees.show', $this->employee->id))->assertOk();

    // Viewing without the manage permission must not create anything.
    expect(EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

it('blocks an unauthorized user from regenerating the token', function (): void {
    $this->actingAs($this->admin)->get(route('employees.show', $this->employee->id))->assertOk();

    $before = EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->firstOrFail()->token;

    $viewer = User::factory()->create();
    $viewer->assignRole('Employee Viewer');

    $this->actingAs($viewer)
        ->post(route('employees.feedback-qr.regenerate', $this->employee->id))
        ->assertForbidden();

    expect(EmployeeFeedbackToken::query()->firstOrFail()->token)->toBe($before);
});
