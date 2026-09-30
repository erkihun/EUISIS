<?php

declare(strict_types=1);

/*
 * Admin cards are tonal in the light theme (resources/css/app.css, "Tonal
 * cards"): tinted instead of white, tinted by meaning on KPI cards, and the
 * dark theme and employee portal left as they were.
 */

function tonalSource(string $path): string
{
    return (string) file_get_contents(__DIR__.'/../../'.$path);
}

it('defines light card tints and scopes them to the admin shell in light theme', function (): void {
    $css = tonalSource('resources/css/app.css');

    expect($css)
        ->toMatch('/--app-card:\s*#edf1fb;/')
        ->toMatch('/--tone-success-bg:\s*#dff3ea;/')
        ->toContain(':root:not(.dark) .admin-shell #main-content :is(.rounded-card, .rounded-panel')
        // Controls and floating menus never take the card tint.
        ->toContain(':not(input, select, textarea, button, .fixed, .absolute, .inline-flex)')
        // Each KPI tone has its own tint.
        ->toContain(':root:not(.dark) .card-tone-info')
        ->toContain(':root:not(.dark) .card-tone-danger');

    // The dark block does not define card tints, so dark surfaces are unchanged.
    preg_match('/\n\.dark\s*\{([^}]*)\}/s', $css, $dark);
    expect($dark[1] ?? '')->not->toContain('--app-card');
});

it('marks the admin layout, not the employee portal, as the tonal shell', function (): void {
    expect(tonalSource('resources/js/Layouts/AuthenticatedLayout.tsx'))
        ->toContain("variant === 'portal' ? 'portal-shell' : 'admin-shell'");
});

it('tints KPI cards by tone instead of painting them white', function (): void {
    $kpi = tonalSource('resources/js/Components/dashboard/KpiCard.tsx');

    expect($kpi)
        ->toContain("card: 'card-tone-success'")
        ->toContain("card: 'card-tone-danger'")
        ->toContain('style.card,')
        ->not->toContain('border-gray-200 bg-white p-4');
});
