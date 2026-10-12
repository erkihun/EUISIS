<?php

declare(strict_types=1);

use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackStatus;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackFormToken;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublicFeedbackScenario;

/*
 * QA brief §11 and §32: the browser can name any target, type, source, status
 * or reviewer it likes — the server ignores all of it. A one-time form token
 * cannot be replayed against another target, and an accidental double POST
 * yields one row.
 */

const PF_FORGED = [
    'employee_id' => '00000000-0000-0000-0000-000000000001',
    'employee_target_id' => '00000000-0000-0000-0000-000000000001',
    'organization_target_id' => '00000000-0000-0000-0000-000000000002',
    'organization_id' => '00000000-0000-0000-0000-000000000003',
    'employee_assignment_id' => '00000000-0000-0000-0000-000000000004',
    'target_type' => 'organization',
    'source_type' => 'organization_feedback_qr',
    'type' => 'complaint',
    'status' => 'closed',
    'resolved_at' => '2020-01-01 00:00:00',
    'closed_at' => '2020-01-01 00:00:00',
    'assigned_at' => '2020-01-01 00:00:00',
    'reviewed_by' => 1,
    'reviewed_at' => '2020-01-01 00:00:00',
    'review_note' => 'forged',
    'reference_number' => 'PSC-2020-000001',
    'submitter_identity_mode' => 'identified',
    'ip_address' => '10.0.0.1',
];

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->a = PublicFeedbackScenario::employeeTarget('TPA');
    $this->b = PublicFeedbackScenario::employeeTarget('TPB');
    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;
    $this->orgTokenB = app(OrganizationFeedbackQrService::class)->ensureActive($this->b->organization, $this->superAdmin)->public_token;
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

it('ignores every forged target, type, source and workflow field on the employee flow', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', ['message' => 'Forged employee flow.'] + PF_FORGED)->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->type->value)->toBe('suggestion')
        ->and($row->target_type)->toBe(PublicFeedbackTargetType::Employee)
        ->and($row->source_type)->toBe(PublicFeedbackSourceType::EmployeeCardQr)
        ->and($row->employee_id)->toBe($this->a->employee->id)
        ->and($row->organization_target_id)->toBeNull()
        ->and($row->organization_id)->toBe($this->a->organization->id)
        ->and($row->employee_assignment_id)->toBe($this->a->assignment->id)
        ->and($row->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and($row->resolved_at)->toBeNull()
        ->and($row->closed_at)->toBeNull()
        ->and($row->reviewed_by)->toBeNull()
        ->and($row->review_note)->toBeNull()
        ->and($row->reference_number)->toBeNull()
        ->and($row->submitter_identity_mode->value)->toBe('optional')
        ->and($row->ip_address)->toBe('127.0.0.1');
});

it('ignores every forged target, type, source and workflow field on the institution flow', function (): void {
    $this->post('/feedback/'.$this->orgToken.'/complaint', ['message' => 'Forged institution flow.'] + PF_FORGED + ['target_type' => 'employee', 'source_type' => 'employee_card_qr', 'employee_id' => $this->a->employee->id])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->type->value)->toBe('complaint')
        ->and($row->target_type)->toBe(PublicFeedbackTargetType::Organization)
        ->and($row->source_type)->toBe(PublicFeedbackSourceType::OrganizationFeedbackQr)
        ->and($row->employee_id)->toBeNull()
        ->and($row->organization_target_id)->toBe($this->a->organization->id)
        ->and($row->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and($row->reference_number)->not->toBe('PSC-2020-000001')
        ->and($row->reference_number)->toStartWith('PSC-')
        ->and($row->closed_at)->toBeNull();
});

it('cannot replay a one-time form token against another target, and a double POST stores once', function (): void {
    $formToken = app(PublicFeedbackFormToken::class)->issue();

    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', ['message' => 'First target.', 'form_token' => $formToken])->assertRedirect();
    $this->post('/feedback/'.$this->orgTokenB.'/suggestion', ['message' => 'Replayed elsewhere.', 'form_token' => $formToken])->assertSessionHasErrors('form_token');
    $this->post('/service-feedback/'.$this->b->token->token.'/suggestion', ['message' => 'Replayed elsewhere.', 'form_token' => $formToken])->assertSessionHasErrors('form_token');

    expect(EmployeeServiceFeedback::query()->count())->toBe(1)
        ->and(EmployeeServiceFeedback::query()->sole()->employee_id)->toBe($this->a->employee->id);

    // Same payload twice within the window (double click, network retry): one row.
    $this->post('/service-feedback/'.$this->b->token->token.'/complaint', ['message' => 'Double click.'])->assertRedirect();
    $this->post('/service-feedback/'.$this->b->token->token.'/complaint', ['message' => 'Double click.'])->assertRedirect();
    expect(EmployeeServiceFeedback::query()->where('employee_id', $this->b->employee->id)->count())->toBe(1);
});

it('refuses to type a submission outside the three public types', function (): void {
    $this->get('/service-feedback/'.$this->a->token->token.'/grievance')->assertNotFound();
    $this->post('/service-feedback/'.$this->a->token->token.'/grievance', ['message' => 'x'])->assertNotFound();
    $this->post('/feedback/'.$this->orgToken.'/appeal', ['message' => 'x'])->assertNotFound();
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});
