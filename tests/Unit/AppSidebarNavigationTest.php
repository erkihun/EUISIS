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
