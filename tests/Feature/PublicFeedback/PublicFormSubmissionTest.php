<?php

declare(strict_types=1);

use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Tests\Support\PublicFeedbackScenario;

/*
 * The QR page's forms post every field they hold, empty or not. A suggestion
 * form still carries the complaint-only keys (incident date and place) as empty
 * values, and once those keys were validated as a date and a string, every
 * suggestion failed on a field the suggestion form never shows: the visitor saw
 * no error and no receipt. These tests post exactly what the browser sends.
 */

beforeEach(function (): void {
    app()->setLocale('en');

    Permission::findOrCreate('service_feedback.view', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');

    $this->employee = PublicFeedbackScenario::employeeTarget('PFS');
    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->employee->organization, $admin)->public_token;
});

/**
 * What the public Tip-off and Complaint forms send when only the required
 * fields are filled in — every optional field arrives empty, exactly as the
 * browser posts it. A Tip-off is anonymous; a Complaint is named.
 *
 * @return array<string, mixed>
 */
function browserEntryPayload(string $type, string $message): array
{
    $common = [
        'form_token' => '',
        'position_service_id' => '',
        'message' => $message,
        'client_email' => '',
        'incident_date' => '',
        'incident_location' => '',
    ];

    return $type === 'suggestion'
        ? $common + [
            'tip_type' => 'CORRUPTION',
            'client_name' => '',
            'client_contact' => '',
            'involved_parties' => '',
            'submitter_address' => ['city' => '', 'sub_city' => '', 'woreda' => '', 'detail' => ''],
        ]
        : $common + [
            'client_name' => 'Abebe Kebede',
            'client_contact' => '0911 22 33 44',
            'submitter_organization' => '',
        ];
}

it('accepts a Tip-off and a complaint exactly as the browser form sends them', function (string $target, string $type): void {
    $url = $target === 'organization'
        ? '/feedback/'.$this->orgToken.'/'.$type
        : '/service-feedback/'.$this->employee->token->token.'/'.$type;

    $this->from($url)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post($url, browserEntryPayload($type, "A {$type} about the {$target}."))
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertSessionHas('feedback_submitted', true);

    $row = EmployeeServiceFeedback::query()->where('type', $type)->sole();
    expect($row->submitter_address)->toBeNull()
        ->and($row->involved_parties)->toBeNull()
        ->and($row->client_email)->toBeNull();
})->with([
    'Tip-off to an institution' => ['organization', 'suggestion'],
    'complaint to an institution' => ['organization', 'complaint'],
    'Tip-off about an employee' => ['employee', 'suggestion'],
    'complaint about an employee' => ['employee', 'complaint'],
]);

it('stores the optional when-and-where of a Tip-off', function (): void {
    $url = '/feedback/'.$this->orgToken.'/suggestion';

    $this->from($url)
        ->post($url, ['message' => 'Asked for a fee.', 'tip_type' => 'CORRUPTION', 'incident_date' => '2026-10-01', 'incident_location' => 'Front desk'])
        ->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->incident_date?->toDateString())->toBe('2026-10-01')
        ->and($row->incident_location)->toBe('Front desk');
});
