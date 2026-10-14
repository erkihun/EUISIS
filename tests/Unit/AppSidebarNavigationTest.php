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
        // The Executive Situation Room is leadership's own category, first.
        ['situationRoom'],
        ['organization', 'hrMasterData', 'employeeManagement', 'identity', 'transferManagement', 'dailyActivity', 'fieldWork', 'performance', 'assessments', 'organizationDesign'],
        ['serviceManagement', 'serviceFeedback', 'providers', 'cafeteria', 'transport'],
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
