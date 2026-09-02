<?php

declare(strict_types=1);

test('position status conditionally renders the organization filter and column for global users only', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Positions/Status.tsx');

    expect($source)
        ->toContain('isOrganizationScoped: boolean;')
        ->toContain('!isOrganizationScoped && (')
        ->toContain("...(isOrganizationScoped ? [] : [t('positions.organization')])")
        ->toMatch('/!isOrganizationScoped && \(\s*<select[\s\S]*?organizations\.map/')
        ->toMatch('/!isOrganizationScoped && \(\s*<td[^>]*>\{organizationName\}<\/td>/');
});
