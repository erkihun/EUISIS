<?php

declare(strict_types=1);

it('places code rules in the administration system menu', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $adminStart = strpos($sidebar, 'const adminGroups');
    $systemStart = strpos($sidebar, "labelKey: 'nav.adminSystem'", $adminStart);
    $codeRules = strpos($sidebar, "routeName: 'code-rules.index'");

    expect(substr_count($sidebar, "routeName: 'code-rules.index'"))->toBe(1)
        ->and($adminStart)->not->toBeFalse()
        ->and($systemStart)->not->toBeFalse()
        ->and($codeRules)->toBeGreaterThan($systemStart)
        ->and($sidebar)->toContain("SIDEBAR_GROUPS_STORAGE_KEY = 'euisis-sidebar-open-groups'")
        ->toContain('window.localStorage.setItem(SIDEBAR_GROUPS_STORAGE_KEY')
        ->toContain('for (const key of activeGroupKeys)')
        ->toContain('aria-controls={panelId}');
});

it('keeps Field Work management separate from employee self-service', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $english = file_get_contents(__DIR__.'/../../resources/js/i18n/en/navigation.ts');
    $amharic = file_get_contents(__DIR__.'/../../resources/js/i18n/am/navigation.ts');

    expect(substr_count($sidebar, "key: 'fieldWork'"))->toBe(1)
        ->and($sidebar)->toContain("labelKey: 'nav.fieldWorkManagement'")
        ->toContain("routeName: 'field-work.pending'")
        ->toContain("anyPermission: ['field_work.approve', 'field_work.return', 'field_work.reject']")
        ->toContain("key: 'portalFieldWork'")
        ->toContain("routeName: 'employee.field-work.index'")
        ->and($english)->toContain("fieldWorkManagement: 'Field Work Management'")
        ->and($amharic)->toContain("fieldWorkManagement: 'የመስክ ሥራ አስተዳደር'");
});
