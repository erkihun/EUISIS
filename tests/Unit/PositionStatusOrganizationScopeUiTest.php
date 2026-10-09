<?php

declare(strict_types=1);

test('position status conditionally renders the organization filter and column for global users only', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Positions/Status.tsx');

    expect($source)
        ->toContain('isOrganizationScoped: boolean;')
        ->toContain('!isOrganizationScoped && (')
        // The column header names the organization only for global users ...
        ->toContain('isOrganizationScoped ? t(\'positions.organizationUnit\') : `${t(\'positions.organizationUnit\')} / ${t(\'positions.organization\')}`')
        // ... the organization filter is rendered only for them ...
        ->toMatch('/!isOrganizationScoped && \(\s*<Select[\s\S]*?organizations\.map/')
        // ... and so is the organization name beside each position's unit.
        ->toMatch('/!isOrganizationScoped && organizationName !== departmentName && <p[^>]*>\{organizationName\}<\/p>/');
});
