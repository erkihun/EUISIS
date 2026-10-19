<?php

declare(strict_types=1);

it('starts admin navigation with organization structure, HR master data, then employee management', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');

    expect($sidebar)->toMatch("/labelKey: 'nav\\.sidebarPeople', keys: \\['organization', 'hrMasterData', 'employeeManagement'/");
});

it('lists every admin group once in workflow order', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $definitions = substr($sidebar, strpos($sidebar, 'const navGroups:'), strpos($sidebar, 'const dashboardNav:') - strpos($sidebar, 'const navGroups:'));
    $sections = substr($sidebar, strpos($sidebar, 'const sections:'), strpos($sidebar, 'interface Props') - strpos($sidebar, 'const sections:'));

    preg_match_all("/^[ \\t]*key: '([^']+)'/m", $definitions, $groupMatches);
    preg_match_all('/keys: \\[([^\\]]+)\\]/', $sections, $sectionMatches);
    $groups = array_map(static function (string $list): array {
        preg_match_all("/'([^']+)'/", $list, $keys);

        return $keys[1];
    }, $sectionMatches[1]);

    expect($groups)->toBe([
        ['organization', 'hrMasterData', 'employeeManagement', 'identity', 'transferManagement', 'dailyActivity', 'fieldWork', 'performance', 'assessments', 'organizationDesign'],
        ['situationRoom', 'intelligence', 'serviceManagement', 'serviceFeedback', 'providers', 'cafeteria', 'transport'],
        ['grievances', 'discipline', 'tribunalCourt', 'auditMonitoring'],
        ['myWork'],
    ]);

    $defined = $groupMatches[1];
    $rendered = array_merge(...$groups);
    sort($defined);
    sort($rendered);
    expect($rendered)->toBe($defined);
});

it('keeps feedback pages together under Service Feedback, not Service Management', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $serviceStart = strpos($sidebar, "key: 'serviceManagement'");
    $feedbackStart = strpos($sidebar, "key: 'serviceFeedback'");
    $cafeteriaStart = strpos($sidebar, "key: 'cafeteria'");

    expect($feedbackStart)->not->toBeFalse();

    $serviceGroup = substr($sidebar, $serviceStart, $feedbackStart - $serviceStart);
    $feedbackGroup = substr($sidebar, $feedbackStart, $cafeteriaStart - $feedbackStart);

    foreach (['dashboard', 'index', 'reports'] as $page) {
        $route = "routeName: 'service-feedback.admin.{$page}'";
        expect($feedbackGroup)->toContain($route)
            ->and($serviceGroup)->not->toContain($route)
            ->and(substr_count($sidebar, $route))->toBe(1);
    }
});

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

it('gates the Entitlements and Service Providers links with the permissions their pages require', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');

    // The pages authorize entitlements.view / service-providers.viewAny (EntitlementPolicy,
    // ServiceProviderPolicy); without the same gate every admin saw both links and got a 403.
    expect($sidebar)
        ->toMatch("/routeName: 'entitlements\\.index',[^}]*permission: 'entitlements\\.view' \\}/")
        ->toMatch("/routeName: 'service-providers\\.index',[^}]*permission: 'service-providers\\.viewAny' \\}/");
});

it('keeps Employee Discipline its own group, apart from grievances and public complaints', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $grievanceStart = strpos($sidebar, "key: 'grievances'");
    $disciplineStart = strpos($sidebar, "key: 'discipline'");
    $tribunalStart = strpos($sidebar, "key: 'tribunalCourt'");
    $grievanceGroup = substr($sidebar, $grievanceStart, $disciplineStart - $grievanceStart);
    $disciplineGroup = substr($sidebar, $disciplineStart, $tribunalStart - $disciplineStart);

    expect(substr_count($sidebar, "key: 'discipline'"))->toBe(1)
        ->and($disciplineGroup)->toContain("routeName: 'discipline.cases.index'")
        ->toContain("routeName: 'employee.discipline.index'")
        ->not->toContain('service-feedback')
        ->not->toContain('grievances.')
        ->and($grievanceGroup)->not->toContain('discipline.')
        ->and($sidebar)->toContain("routeName: 'employee.discipline.index', labelKey: 'nav.myDisciplinaryCases', icon: ScaleIcon, permission: 'discipline.view_own', listedInAdmin: true");
});

it('groups Administrative Tribunal and Court Cases under Tribunal Court, out of Grievances', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $english = file_get_contents(__DIR__.'/../../resources/js/i18n/en/navigation.ts');
    $amharic = file_get_contents(__DIR__.'/../../resources/js/i18n/am/navigation.ts');

    $grievanceStart = strpos($sidebar, "key: 'grievances'");
    $disciplineStart = strpos($sidebar, "key: 'discipline'");
    $tribunalStart = strpos($sidebar, "key: 'tribunalCourt'");
    $tribunalEnd = strpos($sidebar, "key: 'serviceManagement'");
    $grievanceGroup = substr($sidebar, $grievanceStart, $disciplineStart - $grievanceStart);
    $tribunalGroup = substr($sidebar, $tribunalStart, $tribunalEnd - $tribunalStart);

    // The Tribunal menu moved out of the Grievances group into Tribunal Court.
    expect(substr_count($sidebar, "key: 'tribunalCourt'"))->toBe(1)
        ->and(substr_count($sidebar, "key: 'courtCases'"))->toBe(0)
        ->and($grievanceGroup)->not->toContain("routeName: 'tribunal-cases.index'")
        ->and($tribunalGroup)->toContain("labelKey: 'nav.groupTribunalCourt'")
        ->toContain("routeName: 'tribunal-cases.index'")
        ->toContain("routeName: 'court-cases.index'")
        ->and(substr_count($sidebar, "routeName: 'tribunal-cases.index'"))->toBe(1)
        // The label was renamed to Tribunal Court; the old group label is gone.
        ->and($english)->toContain("groupTribunalCourt: 'Tribunal Court'")
        ->not->toContain('groupCourtCases:')
        ->and($amharic)->toContain("groupTribunalCourt: 'የአስተዳደር ፍርድ ቤት'")
        ->not->toContain('groupCourtCases:');
});

it('gives every menu and submenu page its own descriptive icon', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $icons = file_get_contents(__DIR__.'/../../resources/js/Components/NavIcons.tsx');
    preg_match_all('/^export const (\w+) = /m', $icons, $exported);

    // Each group's pages: navGroups and portal groups by key, the Administration sub-clusters by label.
    preg_match_all("/(?:key: '(\w+)',\s+labelKey: '[^']+',\s+icon: (\w+),\s+items: \[|labelKey: '(nav\.admin\w+)',\s+items: \[)(.*?)\n\s+\],/s", $sidebar, $groups, PREG_SET_ORDER);
    expect(count($groups))->toBeGreaterThanOrEqual(28);

    $selfService = [];
    foreach ($groups as $group) {
        $name = $group[1] ?: $group[3];
        preg_match_all("/\{ routeName: '[^']+',(?: tab: '[^']+',)? labelKey: '[^']+',\s+icon: (\w+)(?<listed>[^}]*)\}/", $group[4], $pages, PREG_SET_ORDER);
        $pageIcons = array_column($pages, 1);

        expect($pageIcons)->not->toBeEmpty()
            ->and(array_values(array_diff($pageIcons, $exported[1])))->toBe([])
            ->and(array_keys(array_filter(array_count_values($pageIcons), fn (int $n): bool => $n > 1)))->toBe([], "{$name} reuses an icon");

        if (str_starts_with($name, 'portal')) {
            foreach ($pages as $page) {
                if (! str_contains($page['listed'], 'listedInAdmin')) {
                    $selfService[] = $page[1];
                }
            }
        }
    }

    // Staff see the portal pages flattened into one Self-service group, so they must differ there too.
    expect(array_keys(array_filter(array_count_values($selfService), fn (int $n): bool => $n > 1)))->toBe([])
        // Submenu pages render their icon (smaller) instead of hiding it.
        ->and($sidebar)->toContain("nested && !compact\n                        ? ['h-4 w-4 shrink-0'");
});

it('lists the admin pages that have no other entry point', function (): void {
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');

    foreach ([
        "routeName: 'assessment-workspace.index'" => "permission: 'assessments.view_assigned'",
        "routeName: 'assessment-reviews.index'" => "anyPermission: ['assessments.review', 'assessment_assignments.view']",
        "routeName: 'employee.assessments.index'" => "permission: 'assessments.view_own_result'",
        "routeName: 'vacancy-applications.index'" => "permission: 'vacancy-applications.viewAny'",
        "routeName: 'print-batches.index'" => "anyPermission: ['id-cards.createPrintBatch', 'cards.manage']",
        "routeName: 'id-card-templates.index'" => "permission: 'id_card_templates.view'",
        "routeName: 'nfc-management.credentials.index'" => "permission: 'nfc_credentials.view'",
        "routeName: 'cafeteria.special-days.index'" => "permission: 'cafeteria_special_days.view'",
        "routeName: 'cafeteria.employee-exclusions.index'" => "permission: 'cafeteria_employee_exclusions.view'",
    ] as $route => $gate) {
        expect(substr_count($sidebar, $route))->toBe(1);
        $line = strstr(substr($sidebar, strpos($sidebar, $route)), "\n", true);
        expect($line)->toContain($gate);
    }

    // The discipline portal needs the account's own employee record, so admins without one never get a 403 link.
    expect($sidebar)->toContain("permission: 'discipline.view_own', employeeOnly: true")
        ->toContain('&& (!item.employeeOnly || hasEmployeeRecord)');
});
