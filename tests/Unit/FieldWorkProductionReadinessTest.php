<?php

declare(strict_types=1);
it('keeps the supervisor approval queue SQL scoped and paginated', function (): void {
    $controller = file_get_contents(__DIR__.'/../../app/Http/Controllers/Web/FieldWorkController.php');
    $resolver = file_get_contents(__DIR__.'/../../app/Services/FieldWork/FieldWorkSupervisorResolver.php');

    expect($controller)
        ->toContain('->applyApprovalScope(')
        ->toContain('->paginate(25)')
        ->not->toContain('->get()->filter(')
        ->and($resolver)
        ->toContain("->where('reviewer_user_id', \$actor->id)")
        ->toContain("->where('employee_id', \$employee->id)")
        ->toContain('$assignment->include_sub_units')
        ->toContain('descendantUnitIds(');
});

it('preserves the resolved supervisor and localized field work messages', function (): void {
    $service = file_get_contents(__DIR__.'/../../app/Services/FieldWork/FieldWorkService.php');
    $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_10_09_121500_add_supervisor_snapshot_to_field_work_requests.php');
    $english = require __DIR__.'/../../lang/en/field-work.php';
    $amharic = require __DIR__.'/../../lang/am/field-work.php';

    expect($service)
        ->toContain("'supervisor_user_id' => \$supervisor->id")
        ->toContain("'supervisor_name_snapshot'")
        ->and($migration)
        ->toContain("'supervisor_user_id'")
        ->toContain("'supervisor_employee_id'")
        ->and($english['supervisor_not_resolved'])->toContain('SUPERVISOR_NOT_RESOLVED')
        ->and($amharic['supervisor_not_resolved'])->toContain('SUPERVISOR_NOT_RESOLVED');
});

it('clears destination fields that do not belong to the selected destination type', function (): void {
    $service = file_get_contents(__DIR__.'/../../app/Services/FieldWork/FieldWorkService.php');

    expect($service)
        ->toContain("\$values['destination_organization_id'] = null")
        ->toContain("\$values['destination_organization_unit_id'] = null")
        ->toContain("\$values['external_organization_name'] = null")
        ->toContain("FieldWorkDestinationType::ExternalOrganization && blank(\$data['external_organization_name'] ?? null)");
});

it('binds GPS idempotency to one operation and permits correction after a blocked observation', function (): void {
    $service = file_get_contents(__DIR__.'/../../app/Services/FieldWork/FieldWorkLocationService.php');
    $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_10_09_121600_allow_blocked_field_work_location_retries.php');

    expect($service)
        ->toContain('$existing->field_work_request_id !== $request->id')
        ->toContain('$existing->field_work_participant_id !== $participant->id')
        ->toContain('$existing->event_type !== $type')
        ->toContain("->where('review_state', '!=', 'blocked')")
        ->and($migration)
        ->toContain('fwle_one_accepted_event_per_participant_type')
        ->toContain("where review_state <> 'blocked'");
});

it('registers every Field Work permission in the catalog and default role matrix', function (): void {
    $catalog = require __DIR__.'/../../database/seeders/data/field-work-permissions.php';
    $names = array_column($catalog, 'name');
    $matrix = file_get_contents(__DIR__.'/../../app/Support/Rbac/DefaultRoleMatrix.php');

    expect($names)->toHaveCount(19)
        ->and(array_unique($names))->toHaveCount(19)
        ->and($matrix)->toContain('field_work.complete')
        ->toContain('field_work.approve', 'field_work.return', 'field_work.reject');
});
