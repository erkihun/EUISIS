<?php

declare(strict_types=1);

it('provides a responsive and keyboard-accessible admin shell', function (): void {
    $layout = file_get_contents(__DIR__.'/../../resources/js/Layouts/AuthenticatedLayout.tsx');
    $header = file_get_contents(__DIR__.'/../../resources/js/Components/AppHeader.tsx');
    $breadcrumbs = file_get_contents(__DIR__.'/../../resources/js/Components/Breadcrumbs.tsx');

    expect($layout)
        ->toContain('DialogBackdrop')
        ->toContain('DialogPanel')
        ->toContain("href=\"#main-content\"")
        ->toContain('id="main-content"')
        ->toContain('useEffect(() => setSidebarOpen(false), [pageUrl])')
        ->and($header)
        ->toContain('sticky top-0 z-20')
        ->toContain('sm:hidden')
        ->toContain("aria-label={t('nav.commandMenu')}")
        ->and($breadcrumbs)
        ->toContain('<ol className=')
        ->toContain('aria-current="page"')
        ->toContain('overflow-x-auto');
});
