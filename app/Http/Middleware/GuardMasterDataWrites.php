<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses Golden Path master-data changes while `core.master_data_writes` is
 * 'frozen' (a cutover or restore window). Reads and everything that is not
 * master data pass untouched. A browser gets the previous page back with an
 * explanation; an API or JSON caller gets 423 Locked.
 */
class GuardMasterDataWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('core.master_data_writes', 'open') !== 'frozen' || $request->isMethodSafe()) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();
        if ($name === '' || ! Str::startsWith($name, (array) config('core.master_data_routes', []))) {
            return $next($request);
        }

        $message = __('backbone.master_data_frozen');
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message, 'error_code' => 'master_data_frozen'], Response::HTTP_LOCKED);
        }

        return back()->with('flash', ['type' => 'error', 'message' => $message]);
    }
}
