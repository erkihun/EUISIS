<?php

declare(strict_types=1);

/*
 * The admin shell (sidebar, header, page heading band) follows one design:
 * Solar icons in a single weight, 36px controls with the control radius, a
 * dot-matrix band behind page titles, and motion that respects the user's
 * reduced-motion preference (docs/ui-navigation.md, "Admin shell").
 */
function adminShellSource(string $path): string
{
    return (string) file_get_contents(__DIR__.'/../../'.$path);
}

it('draws the shell navigation with Solar icons from one adapter module', function (): void {
    $icons = adminShellSource('resources/js/Components/NavIcons.tsx');

    // Every import is the linear weight; the shell never mixes weights.
    preg_match_all("/from '(@solar-icons\/react\/[^']+)'/", $icons, $imports);
    expect($imports[1])->not->toBeEmpty()
        ->and(array_filter($imports[1], fn (string $path): bool => ! str_starts_with($path, '@solar-icons/react/linear/')))->toBe([]);

    foreach (['AppSidebar', 'AppHeader', 'Breadcrumbs', 'NotificationBell', 'ThemeToggle'] as $component) {
        expect(adminShellSource("resources/js/Components/{$component}.tsx"))
            ->toContain("from '@/Components/NavIcons'")
            ->not->toContain("from '@/Components/Icons'");
    }
});

it('gives the header a sidebar toggle and 36px controls', function (): void {
    $header = adminShellSource('resources/js/Components/AppHeader.tsx');
    $layout = adminShellSource('resources/js/Layouts/AuthenticatedLayout.tsx');

    expect($header)
        ->toContain('onToggleSidebar')
        ->toContain("aria-label={t(sidebarCollapsed ? 'nav.expandSidebar' : 'nav.collapseSidebar')}")
        ->toContain('h-9 w-9 shrink-0 items-center justify-center rounded-md')
        ->and($layout)
        ->toContain('onToggleSidebar={toggleSidebarCollapse}');
});

it('lays a dot-matrix band behind page titles and honours reduced motion', function (): void {
    $css = adminShellSource('resources/css/app.css');
    $layout = adminShellSource('resources/js/Layouts/AuthenticatedLayout.tsx');

    expect($css)
        ->toContain('.app-dot-grid')
        ->toContain('radial-gradient(')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        // The sidebar scrollbar follows the configured sidebar colour, never a fixed slate.
        ->not->toContain('.sidebar-scroll::-webkit-scrollbar-thumb { background: #334155;')
        ->and($layout)->toContain('app-page-heading app-dot-grid');
});
