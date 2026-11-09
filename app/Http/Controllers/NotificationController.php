<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\NotificationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->latest()->orderByDesc('id')->paginate(10);
        $locale = NotificationPresenter::locale($request);

        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'items' => $notifications->getCollection()
                ->map(fn ($notification): array => NotificationPresenter::present($notification, $locale))
                ->values(),
            'page' => $notifications->currentPage(),
            'last_page' => $notifications->lastPage(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return response()->json(['success' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
