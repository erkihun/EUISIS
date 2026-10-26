<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CoreNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Core/Notifications (ADR 0011): the reader's own notifications, newest first, in the page language. */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';
        $unreadOnly = $request->boolean('unread');
        $page = CoreNotification::query()->where('user_id', $request->user()->getKey())
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();

        return Inertia::render('Core/Notifications', [
            'notifications' => [
                'data' => collect($page->items())->map(fn (CoreNotification $n): array => $n->text($locale) + [
                    'id' => $n->id, 'event' => $n->event, 'url' => $n->url, 'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
                ])->values(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                    'from' => $page->firstItem(), 'to' => $page->lastItem()],
            ],
            'unread' => CoreNotification::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->count(),
            'unread_only' => $unreadOnly,
            'read_url' => route('core.notifications.read'),
            'url' => route('core.notifications'),
        ]);
    }

    /** Marks the given notifications (or all) of the reader as read; nobody else's. */
    public function read(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['uuid']])['ids'] ?? null;
        CoreNotification::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')
            ->when($ids !== null, fn ($q) => $q->whereKey($ids))->update(['read_at' => now()]);

        return back()->with('success', __('oversight.notifications_read'));
    }
}
