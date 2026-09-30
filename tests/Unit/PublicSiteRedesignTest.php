<?php

declare(strict_types=1);

/*
 * Public site redesign (Claude Design canvas "EUISIS Public Site Redesign"):
 * every public page shares the home page's look (hero, cards, motion), and
 * the Verify task fits the first phone screen.
 */

function publicSource(string $path): string
{
    return (string) file_get_contents(__DIR__.'/../../'.$path);
}

it('routes scanned or typed values through the shared resolver', function (): void {
    expect(publicSource('resources/js/Pages/Public/Verify.tsx'))->toContain('resolveVerifyDestination(raw)')
        ->not->toContain('function resolveDestination');

    $resolver = publicSource('resources/js/Components/public/verifyDestination.ts');
    expect($resolver)->toContain('/service-feedback/${value}')->toContain('/id-checker/${uuidMatch[0]}');
});

it('keeps the Verify task in the first phone screen', function (): void {
    $verify = publicSource('resources/js/Pages/Public/Verify.tsx');

    expect($verify)
        ->toContain('breadcrumbs={[{ label: title }]} compact')
        // Shorter than square on phones; the scanner still loads lazily.
        ->toContain('aspect-[4/3]')
        ->toContain("lazy(() => import('@/Components/public/QrScanner'))")
        // Large touch targets and a 16px input (no iOS zoom on focus).
        ->toContain('min-h-[52px]')
        ->toContain('h-12 min-w-0 flex-1')
        ->toContain('text-base');
});

it('gives every public page the home page look', function (): void {
    $kit = publicSource('resources/js/Components/public/PublicPage.tsx');
    $css = publicSource('resources/css/public-site.css');

    expect($kit)
        // The home hero, cards and heading accent, shared through the kit.
        ->toContain('className={`public-hero')
        ->toContain("'public-card group relative")
        ->toContain('public-heading-accent')
        ->toContain('compact = false')
        // Breadcrumbs are hidden on phones so the page task shows first.
        ->toContain('<div className="hidden sm:block">')
        ->and($css)
        ->toContain('linear-gradient(120deg, #101b50 0%')
        ->toContain("[data-motion='on']")
        ->toContain('prefers-reduced-motion: no-preference')
        ->and(publicSource('resources/js/Layouts/PublicLayout.tsx'))
        ->toContain("import '../../css/public-site.css'")
        ->toContain("getBoolean('appearance.enable_ui_animations', true)");
});
