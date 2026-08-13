<?php

declare(strict_types=1);

test('employees index uses the organization structure instead of a positions column', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Index.tsx');

    expect($source)
        ->toContain('<ScopedOrganizationStructure')
        ->not->toContain("t('employees.positionsInOrganization')")
        ->not->toContain('<OrganizationTreePreview');
});

test('employee organization structure supports units positions search and empty states', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/organization-structure/ScopedOrganizationStructure.tsx');

    expect($source)
        ->toContain("t('organizations.searchStructure')")
        ->toContain("t('organizationUnits.organizationUnits')")
        ->toContain("t('organizationUnits.positions')")
        ->toContain("t('organizationUnits.noOrganizationStructureFound')")
        ->toContain("t('organizationUnits.noOrganizationUnitsFound')")
        ->toContain("t('organizations.noPositionsFound')");
});
