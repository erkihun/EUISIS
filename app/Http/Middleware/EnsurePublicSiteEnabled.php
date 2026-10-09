<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SystemSettings\PublicSettingsService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies `public_site.enabled` to the published-content pages only.
 *
 * When an administrator switches the public site off, Home, Announcements,
 * Services and Support show the maintenance notice instead. It is NOT applied
 * to Verify ID Cards, the ID checker or service feedback: those are
 * operational tools a service provider may be using at a counter, and a
 * content outage must not take them down.
 */
class EnsurePublicSiteEnabled
{
    public function __construct(private readonly PublicSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $settings = $this->settings->shareableSettings();

        if ((bool) ($settings['public_site.enabled'] ?? true)) {
            return $next($request);
        }

        return Inertia::render('Public/Unavailable', [
            'notice_en' => $settings['public_site.maintenance_notice_en'] ?? '',
            'notice_am' => $settings['public_site.maintenance_notice_am'] ?? '',
        ])->toResponse($request)->setStatusCode(503);
    }
}
