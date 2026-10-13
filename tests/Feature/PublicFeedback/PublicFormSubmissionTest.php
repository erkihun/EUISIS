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

/** @return array<string, string> What ServiceEntryForm sends when only the message is filled in. */
function browserEntryPayload(string $message): array
{
    return [
        'form_token' => '',
        'position_service_id' => '',
        'category_id' => '',
        'message' => $message,
        'client_name' => '',
        'client_contact' => '',
        'incident_date' => '',
        'incident_location' => '',
    ];
}

it('accepts a suggestion and a complaint exactly as the browser form sends them', function (string $target, string $type): void {
    $url = $target === 'organization'
        ? '/feedback/'.$this->orgToken.'/'.$type
        : '/service-feedback/'.$this->employee->token->token.'/'.$type;

    $this->from($url)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post($url, browserEntryPayload("A {$type} about the {$target}."))
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertSessionHas('feedback_submitted', true);

    expect(EmployeeServiceFeedback::query()->where('type', $type)->count())->toBe(1);
})->with([
    'suggestion to an institution' => ['organization', 'suggestion'],
    'complaint to an institution' => ['organization', 'complaint'],
    'suggestion about an employee' => ['employee', 'suggestion'],
    'complaint about an employee' => ['employee', 'complaint'],
]);

it('still refuses incident details sent with a suggestion', function (): void {
    $url = '/feedback/'.$this->orgToken.'/suggestion';

    $this->from($url)
        ->post($url, ['message' => 'A suggestion.', 'incident_date' => '2026-10-01', 'incident_location' => 'Front desk'])
        ->assertSessionHasErrors(['incident_date', 'incident_location']);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});
