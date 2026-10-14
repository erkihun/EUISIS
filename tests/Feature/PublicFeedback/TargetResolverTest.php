<?php

declare(strict_types=1);

use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use App\Services\ServiceFeedback\PublicFeedbackTargetResolver;
use Tests\Support\PublicFeedbackScenario;

/*
 * One authoritative resolver turns the opaque token in a scanned QR into the
 * target of a submission. Nothing from the browser can replace it (brief §14).
 */

beforeEach(function (): void {
    $this->scenario = PublicFeedbackScenario::employeeTarget('TRS');
    $this->resolver = app(PublicFeedbackTargetResolver::class);
});

it('resolves an active employee feedback token to an employee target with its service context', function (): void {
    $context = $this->resolver->resolveEmployeeToken($this->scenario->token->token);

    expect($context)->not->toBeNull()
        ->and($context->targetType)->toBe(PublicFeedbackTargetType::Employee)
        ->and($context->sourceType)->toBe(PublicFeedbackSourceType::EmployeeCardQr)
        ->and($context->employeeId)->toBe($this->scenario->employee->id)
        ->and($context->organizationTargetId)->toBeNull()
        ->and($context->employeeAssignmentId)->toBe($this->scenario->assignment->id)
        ->and($context->contextOrganizationId)->toBe($this->scenario->organization->id)
        ->and($context->contextUnitId)->toBe($this->scenario->unit->id)
        ->and($context->contextPositionId)->toBe($this->scenario->position->id)
        ->and($context->sourceId)->toBe($this->scenario->token->id)
        ->and($context->targetNameSnapshot)->toBe('Licensing Officer')
        ->and($context->targetCodeSnapshot)->toBe('TRS-EMP-1');
});

it('shows only the full name for an employee target, never the number, office or role', function (): void {
    $display = $this->resolver->resolveEmployeeToken($this->scenario->token->token)->publicDisplay('am');

    expect(array_keys($display))->toBe(['target_type', 'display_name'])
        ->and($display['target_type'])->toBe('employee')
        ->and($display['display_name'])->toBe('Hanna Girma')
        ->and(json_encode($display, JSON_UNESCAPED_UNICODE))->not->toContain('TRS-EMP-1')->not->toContain('የፈቃድ ባለሙያ')->not->toContain('Licensing');
});

it('resolves nothing for an unknown, suspended or revoked employee token', function (): void {
    expect($this->resolver->resolveEmployeeToken(str_repeat('0', 64)))->toBeNull();

    $tokens = app(EmployeeFeedbackTokenService::class);
    $tokens->suspend($this->scenario->token);
    expect($this->resolver->resolveEmployeeToken($this->scenario->token->token))->toBeNull();

    $tokens->activate($this->scenario->token);
    $tokens->revoke($this->scenario->token);
    expect($this->resolver->resolveEmployeeToken($this->scenario->token->token))->toBeNull();
});

it('resolves nothing for an organization token until organization QR codes exist', function (): void {
    expect($this->resolver->resolveOrganizationToken(str_repeat('a', 64)))->toBeNull();
});
