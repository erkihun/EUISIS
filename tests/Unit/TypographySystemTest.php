<?php

declare(strict_types=1);

/*
 * Guards the central typography system (docs/ui-typography.md): one place
 * defines the font stacks, the language attribute switches them, and no page
 * or component picks a font family of its own.
 */

function typographySource(string $path): string
{
    return (string) file_get_contents(__DIR__.'/../../'.$path);
}

/** @return list<string> */
function typographyFrontendFiles(): array
{
    $files = [];

    foreach (['resources/js', 'packages/euisis-ui/src', 'resources/css'] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../'.$root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (preg_match('/\.(tsx?|css)$/', $file->getFilename()) === 1) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }

    return $files;
}

it('defines Inter for English and Noto Sans Ethiopic for Amharic in one token set', function (): void {
    $css = typographySource('resources/css/app.css');

    expect($css)
        ->toMatch("/--font-ui-en:\s*'Inter'/")
        ->toMatch("/--font-ui-am:\s*'Noto Sans Ethiopic'/")
        ->toMatch("/--font-id-card:\s*'Inter'[^;]*'Noto Sans Ethiopic'/")
        // Switched by the language attribute, not by component classes.
        ->toMatch('/\[lang\|="en"\]\s*\{[^}]*--font-ui:\s*var\(--font-ui-en\)/s')
        ->toMatch('/\[lang\|="am"\]\s*\{[^}]*--font-ui:\s*var\(--font-ui-am\)/s')
        ->toMatch('/body\s*\{[^}]*font-family:\s*var\(--font-ui\)/s')
        ->toContain(':where([lang])')
        // Sonner injects a system stack; the override keeps toasts on the token.
        ->toContain('html [data-sonner-toaster]')
        // The old blanket rule overrode monospace codes, icons and charts.
        ->not->toMatch('/:lang\(am\)\s*\*\s*[,{]/')
        ->not->toContain('--font-ethiopic');
});

it('gives Amharic roomier line heights without uppercase or tracking', function (): void {
    $css = typographySource('resources/css/app.css');

    preg_match('/\[lang\|="am"\]\s*\{([^}]*)\}/s', $css, $amharic);

    expect($amharic[1] ?? '')
        ->toContain('--ui-line-height: 1.6')
        ->toContain('--lh-sm: 1.375rem')
        ->and($css)
        ->toContain('.uppercase:lang(am)')
        ->toMatch('/tracking-widest[^{]*\):lang\(am\)\s*\{\s*letter-spacing:\s*normal/s');
});

it('ships only self-hosted woff2 faces at weights 400 to 700', function (): void {
    $fonts = typographySource('resources/css/fonts.css');

    preg_match_all('/font-weight:\s*(\d+)/', $fonts, $weights);
    preg_match_all('/url\(([^)]+)\)/', $fonts, $urls);

    expect(array_values(array_unique($weights[1])))->toEqualCanonicalizing(['400', '500', '600', '700'])
        ->and($fonts)->toContain('font-display: swap')
        ->and($fonts)->not->toMatch('#https?://#')
        ->and($urls[1])->each->toMatch('/node_modules\/@fontsource\/(inter|noto-sans-ethiopic)\/files\/[\w-]+\.woff2/')
        ->and(typographySource('resources/css/app.css'))->toContain("@import './fonts.css';");

    foreach ($urls[1] as $url) {
        expect(file_exists(__DIR__.'/../../resources/css/'.trim($url, "'\"")))->toBeTrue("Missing font file {$url}");
    }
});

it('maps Tailwind font-sans and the semantic sizes to the tokens', function (): void {
    $config = typographySource('tailwind.config.js');

    expect($config)
        ->toContain("sans: ['var(--font-ui)']")
        ->toContain("document: ['var(--font-document)']")
        ->toContain("'page-title'")
        ->toContain("xs: ['var(--fs-xs)', { lineHeight: 'var(--lh-xs)' }]")
        // 800/900 are not shipped.
        ->toContain("black: '700'")
        ->not->toContain('Figtree');
});

it('leaves no hard-coded font family in the frontend', function (): void {
    // Token definitions and the capture helper are the only places allowed to name fonts.
    $allowed = ['resources/css/app.css', 'resources/css/fonts.css', 'resources/js/lib/typography.ts'];
    $conflicting = '/\b(Figtree|Arial|Roboto|Helvetica|Times New Roman|Georgia|Tahoma|Verdana|Abyssinica|Nyala|DejaVu)\b/';
    $offenders = [];

    foreach (typographyFrontendFiles() as $file) {
        if (array_filter($allowed, fn (string $path): bool => str_ends_with($file, $path)) !== []) {
            continue;
        }

        foreach (preg_split('/\R/', (string) file_get_contents($file)) as $number => $line) {
            // Reading a token (`var(--font-…)`, `cssToken('--font-…')`) is the sanctioned way to set a family.
            $setsFamily = preg_match('/font-family\s*:|fontFamily\s*[:=]/', $line) === 1
                && preg_match("/var\\(--font-|cssToken\\('--font-/", $line) !== 1;
            if ($setsFamily || preg_match($conflicting, $line) === 1) {
                $offenders[] = basename($file).':'.($number + 1).': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the ID card preview on the card font token', function (): void {
    expect(typographySource('resources/js/Components/IdCards/BackTextBlock.tsx'))
        ->toContain("fontFamily: 'var(--font-id-card)'")
        ->and(typographySource('resources/css/app.css'))
        ->toContain(':where([data-card-template])')
        // Card exports inline the app fonts instead of skipping them.
        ->and(typographySource('resources/js/hooks/useCardExport.ts'))
        ->toContain('captureFontOptions()')
        ->not->toContain('skipFonts: true')
        ->and(typographySource('resources/js/Components/IdCards/CardPortraitPrintExportModal.tsx'))
        ->toContain('captureFontOptions()');
});

it('lets layouts inherit the locale font instead of setting their own', function (): void {
    foreach ([
        'resources/js/Layouts/AuthenticatedLayout.tsx',
        'resources/js/Layouts/CafeteriaProviderAdminLayout.tsx',
        'resources/js/Layouts/CafeteriaProviderPortalLayout.tsx',
        'resources/js/Layouts/TransportProviderLayout.tsx',
        'resources/js/Layouts/ProviderAuthLayout.tsx',
        'resources/js/Layouts/GuestLayout.tsx',
        'resources/js/Layouts/PublicLayout.tsx',
        'resources/js/Components/employees/portal/PortalPage.tsx',
        'resources/js/Components/AppSidebar.tsx',
        'resources/js/Pages/Welcome.tsx',
    ] as $layout) {
        expect(typographySource($layout))->not->toMatch('/fontFamily|font-family|font-serif/');
    }

    expect(typographySource('resources/views/app.blade.php'))
        ->toContain('<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}">')
        ->toContain('font-sans')
        ->not->toContain('Figtree');
});
