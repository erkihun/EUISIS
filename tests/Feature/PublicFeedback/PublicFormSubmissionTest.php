<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Tests\Support\PublicFeedbackScenario;
use Tests\Support\PublicSubmissionPayload;

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
            'submitter_address' => ['city' => '', 'sub_city' => 'Bole', 'woreda' => '03', 'house_number' => '', 'detail' => ''],
            'complaint_form' => [
                'gender' => 'male', 'id_type' => 'national_id', 'id_number' => 'FAN-0001', 'alt_phone' => '', 'represented' => '0',
                'principal_name' => '', 'poa_reference' => '', 'poa_date' => '', 'unit_or_staff' => '', 'service_type' => 'Trade licence',
                'prior_woreda_date' => '', 'prior_woreda_decision' => '', 'prior_sub_city_date' => '', 'prior_sub_city_decision' => '',
                'remedy_sought' => 'Renew the licence.', 'other_pages' => '', 'declaration' => '1',
            ],
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
    // Empty fields are dropped; a complaint keeps the address and form parts the complainant filled in.
    expect($row->submitter_address)->toBe($type === 'complaint' ? ['sub_city' => 'Bole', 'woreda' => '03'] : null)
        ->and($row->involved_parties)->toBeNull()
        ->and($row->client_email)->toBeNull();
    if ($type === 'complaint') {
        expect($row->complaint_details['complainant'])->toBe(['gender' => 'male', 'id_type' => 'national_id', 'id_number' => 'FAN-0001', 'represented' => false])
            ->and($row->complaint_details['matter'])->toBe(['service_type' => 'Trade licence', 'remedy_sought' => 'Renew the licence.'])
            ->and($row->complaint_details['declared_at'])->not->toBeNull();
    } else {
        expect($row->complaint_details)->toBeNull();
    }
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

it('requires the complaint form: address, sex, identity document, remedy and the declaration', function (): void {
    $url = '/service-feedback/'.$this->employee->token->token.'/complaint';
    $base = ['message' => 'Kept waiting.', 'client_name' => 'Abebe Kebede', 'client_contact' => '0911 22 33 44'];

    $this->post($url, $base)->assertSessionHasErrors(['submitter_address', 'complaint_form']);
    $this->post($url, $base + ['submitter_address' => ['city' => 'Addis Ababa'], 'complaint_form' => ['represented' => '1', 'gender' => 'other']])
        ->assertSessionHasErrors([
            'submitter_address.sub_city', 'submitter_address.woreda', 'complaint_form.gender', 'complaint_form.id_type', 'complaint_form.id_number',
            'complaint_form.principal_name', 'complaint_form.poa_reference', 'complaint_form.remedy_sought', 'complaint_form.declaration',
        ]);
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);

    // Complete, on behalf of someone, with prior handling and documents: stored as given.
    $form = PublicSubmissionPayload::complaintForm();
    $form['complaint_form'] += [
        'represented' => '1', 'principal_name' => 'Almaz Tesfaye', 'poa_reference' => 'POA/123', 'poa_date' => '2026-09-01',
        'prior_woreda_date' => '2026-09-10', 'prior_woreda_decision' => 'Rejected.', 'attached' => ['id_copy', 'poa_copy'], 'other_pages' => '3',
    ];
    $form['submitter_address']['house_number'] = '1122';
    $this->post($url, $base + $form)->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->submitter_address)->toBe(['sub_city' => 'Bole', 'woreda' => '03', 'house_number' => '1122'])
        ->and($row->complaint_details['complainant'])->toMatchArray(['gender' => 'female', 'id_number' => 'AA-123456', 'represented' => true, 'principal_name' => 'Almaz Tesfaye', 'poa_reference' => 'POA/123'])
        ->and($row->complaint_details['matter'])->toMatchArray(['prior_woreda' => ['date' => '2026-09-10', 'decision' => 'Rejected.'], 'attached' => ['id_copy', 'poa_copy'], 'other_pages' => 3, 'remedy_sought' => 'Serve the request.'])
        ->and($row->getRawOriginal('complaint_details'))->not->toContain('AA-123456');

    // A Tip-off never carries the complaint form.
    $this->post('/service-feedback/'.$this->employee->token->token.'/suggestion', ['message' => 'Tip.', 'tip_type' => 'CORRUPTION', 'complaint_form' => ['gender' => 'male']])
        ->assertSessionHasErrors('complaint_form');
});

it('keeps the Tip-off form\'s suspect, violations and confidentiality request, all optional', function (): void {
    $url = '/service-feedback/'.$this->employee->token->token.'/suggestion';

    // Nothing of the form is required.
    $this->post($url, ['message' => 'Bare tip.', 'tip_type' => 'CORRUPTION'])->assertSessionHasNoErrors();
    expect(EmployeeServiceFeedback::query()->sole()->tip_details)->toBeNull();
    EmployeeServiceFeedback::query()->delete();

    // "Other" needs words; an unknown violation or confidentiality value is refused.
    $this->post($url, ['message' => 'Tip.', 'tip_type' => 'CORRUPTION', 'tip_form' => ['violation_types' => ['other', 'theft'], 'confidentiality' => 'maybe']])
        ->assertSessionHasErrors(['tip_form.violation_other', 'tip_form.violation_types.1', 'tip_form.confidentiality']);

    $this->post($url, ['message' => 'Asked for money to issue the permit.', 'tip_type' => 'CORRUPTION', 'tip_form' => [
        'confidentiality' => 'confidential', 'suspect_name' => 'Officer X', 'suspect_unit' => 'Permits desk, team leader', 'suspect_location' => 'Bole / 03',
        'violation_types' => ['bribery', 'other'], 'violation_other' => 'Threats', 'evidence_description' => 'Two witnesses.', 'suspect_unknown' => 'ignored',
    ]])->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->tip_details)->toBe([
        'confidentiality' => 'confidential', 'suspect_name' => 'Officer X', 'suspect_unit' => 'Permits desk, team leader', 'suspect_location' => 'Bole / 03',
        'violation_types' => ['bribery', 'other'], 'violation_other' => 'Threats', 'evidence_description' => 'Two witnesses.',
    ])->and($row->getRawOriginal('tip_details'))->not->toContain('Officer X');

    // A complaint never carries the Tip-off form.
    $this->post('/service-feedback/'.$this->employee->token->token.'/complaint', pfComplete('complaint', ['message' => 'C.', 'tip_form' => ['suspect_name' => 'X']]))
        ->assertSessionHasErrors('tip_form');
});
