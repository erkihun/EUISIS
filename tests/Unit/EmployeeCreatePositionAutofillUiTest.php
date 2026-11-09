<?php

declare(strict_types=1);

test('choosing a position sets both organization and organization unit', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Create.tsx');

    // changePosition must drive organization_id from the position, not just the
    // unit — otherwise the three fields can drift apart.
    expect($source)
        ->toContain('organization_id: position.organization_id ?? form.data.organization_id')
        ->toContain("organization_unit_id: position.organization_unit_id ?? ''");
});

test('the position field explains that organization and unit are auto-filled', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Create.tsx');

    expect($source)->toContain("t('employees.organizationAutoFilledFromPosition')");
});

test('the auto-fill helper text is translated in both locales', function (): void {
    foreach (['en', 'am'] as $locale) {
        $source = file_get_contents(dirname(__DIR__, 2)."/resources/js/i18n/{$locale}/employees.ts");

        expect($source)
            ->toContain('organizationAutoFilledFromPosition:')
            ->toContain('positionUnitMismatch:');
    }
});

test('placement is rendered read-only when a position context is present', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Create.tsx');

    expect($source)
        // Read-only display, not an editable control, when context exists.
        ->toContain('placementContext ? (')
        ->toContain('<ReadOnlyValue')
        ->toContain("t('employees.selectedOrganization')")
        ->toContain("t('employees.selectedOrganizationUnit')")
        ->toContain("t('employees.selectedPosition')")
        // Explicit escape hatch + helper text.
        ->toContain("t('employees.changePosition')")
        ->toContain("t('employees.placementFromPositionContext')");
});

test('an empty state prompts for a vacant position when there is no context', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Create.tsx');

    expect($source)
        ->toContain("t('employees.selectVacantPositionFirst')")
        ->toContain("t('employees.selectPosition')");
});

test('the read-only placement strings are translated in both locales', function (): void {
    foreach (['en', 'am'] as $locale) {
        $source = file_get_contents(dirname(__DIR__, 2)."/resources/js/i18n/{$locale}/employees.ts");

        expect($source)
            ->toContain('selectedOrganizationUnit:')
            ->toContain('changePosition:')
            ->toContain('selectVacantPositionFirst:')
            ->toContain('placementFromPositionContext:');
    }
});

test('the create employee button is blocked and toasts when the position is occupied', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Employees/Index.tsx');

    expect($source)
        ->toContain("selectedPosition?.occupancy_status === 'occupied'")
        ->toContain('selectedPositionIsOccupied ? (')
        // Occupied renders a button that toasts, not a navigating Link.
        ->toContain("toast.error(t('employees.positionOccupiedCannotCreate'))")
        ->toContain('aria-disabled="true"');
});

test('the occupied-position message is translated in both locales', function (): void {
    foreach (['en', 'am'] as $locale) {
        $source = file_get_contents(dirname(__DIR__, 2)."/resources/js/i18n/{$locale}/employees.ts");

        expect($source)
            ->toContain('positionOccupiedCannotCreate:')
            ->toContain('selectVacantPosition:');
    }
});

test('organization statistics render status charts via the shared component', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Organizations/Show.tsx');

    expect($source)
        // Reuses the existing recharts-based component, not a new chart stack.
        ->toContain("import StatusDistribution from '@/Components/dashboard/StatusDistribution'")
        ->toContain('<StatusDistribution')
        ->toContain("t('organizations.positionsByStatus')")
        ->toContain("t('organizations.employeesByStatus')")
        ->toContain("t('organizations.idCardsByStatus')");
});
