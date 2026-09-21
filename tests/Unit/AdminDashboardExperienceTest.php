<?php

declare(strict_types=1);

it('keeps dashboard refreshes focused and prevents overlapping requests', function (): void {
    $dashboard = file_get_contents(__DIR__.'/../../resources/js/Pages/Dashboard/Index.tsx');
    $header = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/DashboardHeader.tsx');
    $english = file_get_contents(__DIR__.'/../../resources/js/i18n/en/dashboard.ts');
    $amharic = file_get_contents(__DIR__.'/../../resources/js/i18n/am/dashboard.ts');

    expect($dashboard)
        ->toContain('const REFRESHED_PROPS')
        ->toContain('if (refreshingRef.current) return')
        ->toContain('only: [...REFRESHED_PROPS]')
        ->toContain('<DashboardHeader header={header} refreshing={refreshing} onRefresh={refreshDashboard}')
        ->toContain("'alerts'")
        ->toContain("'workflowQueues'")
        ->and($header)
        ->toContain('disabled={refreshing}')
        ->toContain("t('dashboard.refresh')")
        ->toContain("t('dashboard.lastRefreshed')")
        ->and($english)
        ->toContain("refresh: 'Refresh'")
        ->and($amharic)
        ->toContain("refresh: 'አድስ'");
});

it('provides keyboard accessible sticky dashboard navigation', function (): void {
    $tabs = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/DashboardTabs.tsx');

    expect($tabs)
        ->toContain("event.key === 'ArrowRight'")
        ->toContain("event.key === 'ArrowLeft'")
        ->toContain("event.key === 'Home'")
        ->toContain("event.key === 'End'")
        ->toContain('tabIndex={active ? 0 : -1}')
        ->toContain('aria-orientation="horizontal"')
        ->toContain('sticky top-14');
});

it('makes dashboard filters responsive and exposes their request state', function (): void {
    $filters = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/DateRangeFilter.tsx');

    expect($filters)
        ->toContain('aria-busy={applying}')
        ->toContain('onStart: () => setApplying(true)')
        ->toContain('onFinish: () => setApplying(false)')
        ->toContain('disabled={applying}')
        ->toContain('w-full')
        ->toContain('sm:w-auto')
        ->not->toContain('<span className="me-1');
});

it('does not truncate dashboard metric labels', function (): void {
    $kpi = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/KpiCard.tsx');
    $stat = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/StatTile.tsx');

    expect($kpi)
        ->toContain('whitespace-normal break-words')
        ->not->toContain('mt-1.5 truncate')
        ->and($stat)
        ->toContain('whitespace-normal break-words')
        ->not->toContain('<dt className="truncate');
});

it('uses a compact responsive operations layout with real actions and attention items', function (): void {
    $dashboard = file_get_contents(__DIR__.'/../../resources/js/Pages/Dashboard/Index.tsx');
    $header = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/DashboardHeader.tsx');
    $metrics = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/MetricGrid.tsx');
    $kpi = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/KpiCard.tsx');
    $activity = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/RecentActivityFeed.tsx');
    $scope = file_get_contents(__DIR__.'/../../app/Services/Dashboard/DashboardScopeService.php');
    $overview = file_get_contents(__DIR__.'/../../resources/js/Components/dashboard/DashboardOverview.tsx');

    expect($dashboard)
        ->toContain('aria-labelledby="dashboard-overview-title"')
        ->toContain('<DashboardOverview')
        ->toContain("{ id: 'overview'")
        ->and($overview)
        ->toContain('<AttentionPanel')
        ->toContain("'xl:col-span-8'")
        ->toContain('<RecentActivityFeed items={recentActivity} t={t} />')
        ->and($header)
        ->toContain('<header className=')
        ->not->toContain("t('dashboard.subtitle')")
        ->toContain('<LocalizedDateDisplay')
        ->and($overview)
        ->toContain('header.quickActions.map')
        ->and($metrics)
        ->toContain('gap-4 lg:gap-5')
        ->toContain('2xl:grid-cols-4')
        ->toContain('variant === \'featured\'')
        ->toContain("'xl:col-span-6'")
        ->toContain("'xl:col-span-4'")
        ->not->toContain('gap-px')
        ->and($kpi)
        ->toContain('rounded-xl border')
        ->not->toContain('hover:-translate-y-0.5')
        ->toContain('const icons:')
        ->toContain('min-h-[112px]')
        ->toContain('dark:text-white')
        ->not->toContain('bg-slate-950')
        ->and($activity)
        ->toContain('divide-y divide-gray-100')
        ->not->toContain('rounded-card border')
        ->and($scope)
        ->toContain("'activity_limit' => 8");
});
