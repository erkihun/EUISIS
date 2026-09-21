<?php

declare(strict_types=1);

it('keeps admin sidebar labels and states readable', function (): void {
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');
    $sidebar = file_get_contents(__DIR__.'/../../resources/js/Components/AppSidebar.tsx');
    $colors = file_get_contents(__DIR__.'/../../resources/js/lib/brandColor.ts');

    expect($css)
        ->toContain('var(--sidebar-fg) 72%')
        ->toContain('var(--sidebar-fg) 12%')
        ->toContain('var(--sidebar-accent) 18%')
        ->and($sidebar)
        ->toContain('text-[15px] font-medium')
        ->toContain('whitespace-normal break-words')
        ->toContain('hidden={collapsed || !isOpen}')
        ->toContain('text-xs font-semibold text-[color:var(--sidebar-muted)]')
        ->and($colors)
        ->toContain('contrastRatio(background, dark) >= contrastRatio(background, light)')
        ->toContain('contrastRatio(brand, background) >= 4.5');
});
