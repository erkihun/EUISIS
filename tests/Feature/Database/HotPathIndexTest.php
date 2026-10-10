<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * Production risk register DB-1/DB-2/DB-7: columns that nearly every module
 * filters or joins on must be indexed. On PostgreSQL a foreign key creates no
 * index, so each of these was a sequential scan at 3M employees.
 */

it('indexes the hot employee-assignment columns', function (string $index): void {
    expect(Schema::hasIndex('employee_assignments', $index))->toBeTrue("missing {$index}");
})->with([
    'ea_employee_current_idx',
    'ea_organization_current_idx',
    'ea_position_idx',
]);

it('indexes hot foreign keys and time columns', function (string $table, string $index): void {
    expect(Schema::hasIndex($table, $index))->toBeTrue("missing {$table}.{$index}");
})->with([
    ['id_cards', 'id_cards_employee_idx'],
    ['employee_duplicate_flags', 'edf_employee_idx'],
    ['employee_documents', 'employee_documents_employee_idx'],
    ['positions', 'positions_organization_unit_idx'],
    ['card_verifications', 'card_verifications_created_idx'],
    ['notifications', 'notifications_notifiable_created_idx'],
    ['audit_logs', 'audit_logs_organization_created_idx'],
    ['audit_logs', 'audit_logs_actor_idx'],
    ['daily_activity_reminders', 'dar_date_type_employee_idx'],
    ['employees', 'employees_created_at_idx'],
    ['employees', 'employees_updated_at_idx'],
    ['cafeteria_transactions', 'ct_employee_date_idx'],
    ['cafeteria_transactions', 'ct_provider_date_idx'],
]);
