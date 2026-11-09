<?php

declare(strict_types=1);

test('positions index renders the scoped organization structure section', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Positions/Index.tsx');

    expect($source)
        ->toContain('<ScopedOrganizationStructure')
        ->toContain('organizations={organizationStructure}')
        ->toContain('isScoped={isOrganizationScoped}');
});

test('positions index no longer renders a standalone organization unit card', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Positions/Index.tsx');

    // The old card rendered its own unit tree via UnitNodeItem next to a
    // "Organization Unit" heading. Units now live inside the structure tree.
    expect($source)
        ->not->toContain('UnitNodeItem')
        ->not->toContain('<OrganizationTreePreview')
        ->not->toContain("t('positions.noOrganizationUnitsHint')");
});

test('positions structure titles are localized and not hard-coded', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/organization-structure/ScopedOrganizationStructure.tsx');

    expect($source)
        ->toContain("t('organizationUnits.yourOrganizationStructure')")
        ->toContain("t('organizations.organizationStructure')")
        ->not->toContain('>Organization Structure<')
        ->not->toContain('>Your Organization Structure<');
});
