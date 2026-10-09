<?php

declare(strict_types=1);

it('keeps the employee portal mobile-safe and makes LAN Vite opt-in', function (): void {
    $root = dirname(__DIR__, 2);
    $vite = file_get_contents($root.'/vite.config.js');
    $header = file_get_contents($root.'/resources/js/Components/PageHeader.tsx');
    $portalCss = file_get_contents($root.'/resources/css/employee-portal.css');

    expect($vite)
        ->toContain("VITE_DEV_SERVER_HOST ?? '127.0.0.1'")
        ->toContain("host: isLanDevServer ? '0.0.0.0' : devServerHost")
        ->toContain('origin: isLanDevServer')
        ->and($header)
        ->toContain('className="min-w-0"')
        ->toContain('break-words text-page-title')
        ->toContain('w-full flex-wrap')
        ->and($portalCss)
        ->toContain('.portal-shell #main-content { min-width: 0; overflow-x: hidden; }')
        ->toContain('@media (max-width: 374px)');
});
