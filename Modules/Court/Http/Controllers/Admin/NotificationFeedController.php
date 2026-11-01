<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\Notifications\AdminNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The header bell of the React screens (EUISIS NotificationBell). */
class NotificationFeedController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        return response()->json($this->feedFor($request)->feed(max(1, (int) $request->query('page', 1))));
    }

    /** `{notice}` is `type:sourceId`, as the feed names its items. */
    public function read(Request $request, string $notice): JsonResponse
    {
        [$type, $id] = array_pad(explode(':', $notice, 2), 2, '');
        abort_unless(in_array($type, AdminNotificationFeed::TYPES, true) && ctype_digit($id), 404);

        $this->feedFor($request)->markRead($type, (int) $id);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->feedFor($request)->markAllRead();

        return response()->json(['ok' => true]);
    }

    private function feedFor(Request $request): AdminNotificationFeed
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return new AdminNotificationFeed($user);
    }
}
