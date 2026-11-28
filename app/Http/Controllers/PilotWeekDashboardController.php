<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PilotWeek\PilotWeekProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The temporary pilot week dashboard (see config/pilot_dashboard.php).
 *
 * No sign-in and no permission: the page is reached only through its own
 * link. A wrong token, a switched-off page and an expired page all answer
 * "not found", so the address tells a stranger nothing.
 */
final class PilotWeekDashboardController extends Controller
{
    public function __invoke(Request $request, string $token, PilotWeekProgress $progress): Response
    {
        abort_unless(PilotWeekProgress::isOpen() && hash_equals(PilotWeekProgress::token(), $token), 404);

        return response()
            ->view('pilot.week-dashboard', [
                'data' => $progress->forWeek($request->query('week') === null ? null : (string) $request->query('week')),
                'token' => $token,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
