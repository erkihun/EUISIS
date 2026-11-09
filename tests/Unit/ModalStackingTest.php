<?php

declare(strict_types=1);

/*
 * The shared Modal draws an `absolute` backdrop and then the panel. In
 * Tailwind 3 the panel's bare `transform` class lifted it above the
 * backdrop; in Tailwind 4 that class applies no transform by itself, the
 * backdrop covered the panel and took every click (no modal form could be
 * submitted). The panel must be positioned itself.
 */
it('keeps the modal panel above its backdrop', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/Modal.tsx');

    expect(preg_match('~<DialogPanel\s+className=\{`([^`]*)`~', $source, $panel))->toBe(1)
        ->and(preg_split('/\s+/', $panel[1]))->toContain('relative');
});
