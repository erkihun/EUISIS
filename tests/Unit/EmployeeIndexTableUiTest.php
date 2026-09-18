<?php

declare(strict_types=1);

function employeeIndexSource(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Index.tsx');
}

function employeeCreateSource(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Create.tsx');
}

test('employee index renders a table-first employee registry with add action', function (): void {
    expect(employeeIndexSource())
        ->toContain('<table')
        ->toContain("t('employees.addNewEmployee')")
        ->toContain('href={createHref}')
        ->toContain("route('employees.create')")
        ->toContain('can.create ? (');
});

test('employee index exposes server-side filters for unit position employment type and status', function (): void {
    expect(employeeIndexSource())
        ->toContain('organization_unit_id')
        ->toContain('position_id')
        ->toContain('employment_type')
        ->toContain('SYSTEM_STATUSES')
        ->toContain("router.get(route('employees.index'), filterForm.data");
});

test('employee index hides the organization column when the controller says to', function (): void {
    expect(employeeIndexSource())
        ->toContain('showOrganizationColumn')
        ->toContain("...(showOrganizationColumn ? [t('employees.columnOrganization')] : [])")
        ->toContain('{showOrganizationColumn && (');
});

test('employee create starts with employment placement before employee details', function (): void {
    $source = employeeCreateSource();

    expect($source)->toContain("title={t('employees.sectionPlacement')}");
    expect(strpos($source, "title={t('employees.sectionPlacement')}"))
        ->toBeLessThan(strpos($source, "title={t('employees.sectionBasic')}"));
});
