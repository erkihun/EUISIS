<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers;

use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Application\Services\Notifications\PortalNotificationFeed;
use Modules\Court\Http\Support\Ui\PortalNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header bell of the applicant and respondent portals (EUISIS
 * NotificationBell), as Admin\NotificationFeedController is for staff. The
 * portal is the one the URL belongs to (/applicant/... or /respondent/...).
 */
class PortalNotificationFeedController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        $feed = $this->feedFor($request, required: false);

        // A person acting as respondent before a respondent record exists has nothing to see yet.
        return response()->json($feed?->feed(max(1, (int) $request->query('page', 1)))
            ?? ['unread_count' => 0, 'items' => [], 'page' => 1, 'last_page' => 1]);
    }

    /** `{notice}` is `type:sourceId`, as the feed names its items. */
    public function read(Request $request, string $notice): JsonResponse
    {
        [$type, $id] = array_pad(explode(':', $notice, 2), 2, '');
        abort_unless(in_array($type, PortalNotificationFeed::TYPES, true) && ctype_digit($id), 404);

        $this->feedFor($request)->markRead($type, (int) $id);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->feedFor($request)->markAllRead();

        return response()->json(['ok' => true]);
    }

    /** @return ($required is true ? PortalNotificationFeed : ?PortalNotificationFeed) */
    private function feedFor(Request $request, bool $required = true): ?PortalNotificationFeed
    {
        $applicant = PortalNavigation::user();
        abort_unless($applicant !== null, 403);

        if (PortalNavigation::portalFor($request) !== 'respondent') {
            return PortalNotificationFeed::forApplicant((int) $applicant->id);
        }

        // The respondent record of the same person (Respondent\NotificationController::resolveRespondentId).
        $respondentId = auth('respondent')->id() ?? Respondent::where('email', $applicant->email)->value('id');
        abort_unless($respondentId !== null || ! $required, 403);

        return $respondentId !== null ? PortalNotificationFeed::forRespondent((int) $respondentId) : null;
    }
}
