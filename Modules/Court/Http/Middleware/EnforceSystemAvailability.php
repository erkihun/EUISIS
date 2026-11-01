<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Modules\Court\Domain\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSystemAvailability
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = SystemSetting::cached();

        if ($settings?->maintenance_mode
            && ! $request->user()
            && ! $request->is('court/admin/*', 'court/login', 'court/logout', 'court/forgot-password', 'password-*')) {
            abort(503, __('court::settings.maintenance_public_message'));
        }

        if ($settings && ! $settings->registration_open
            && $request->is('court/applicant/register')) {
            abort(403, __('court::settings.registration_closed_message'));
        }

        return $next($request);
    }
}
