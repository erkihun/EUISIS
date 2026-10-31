<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Communication\Application\Services\AnnouncementAudience;
use Modules\Communication\Application\Services\CommunicationDirectory;
use Modules\Communication\Application\Services\CommunicationInbox;
use Modules\Communication\Application\Services\CommunicationSettings;
use Modules\Communication\Application\Services\ConversationList;
use Modules\Communication\Domain\Enums\ConversationType;
use Modules\Communication\Domain\Models\AnnouncementReceipt;
use Modules\Communication\Domain\Models\CommunicationTask;
use Modules\Communication\Domain\Models\ConversationMember;

/** Communication landing page and the unread summary the sidebar badge polls. */
class CommunicationController extends Controller
{
    public function __construct(
        private readonly CommunicationInbox $inbox,
        private readonly ConversationList $list,
        private readonly AnnouncementAudience $audience,
        private readonly CommunicationDirectory $directory,
        private readonly CommunicationSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $locale = app()->getLocale();
        $this->inbox->markDelivered($user);

        $recentContacts = ConversationMember::query()
            ->join('conversations', 'conversations.id', '=', 'conversation_members.conversation_id')
            ->where('conversations.type', ConversationType::Direct->value)
            ->whereIn('conversation_members.conversation_id', ConversationMember::query()->select('conversation_id')->where('user_id', $user->getKey())->whereNull('left_at'))
            ->where('conversation_members.user_id', '<>', $user->getKey())
            ->orderByDesc('conversations.last_message_at')->limit(8)
            ->get(['conversation_members.user_id', 'conversation_members.conversation_id']);
        $contacts = $this->directory->present($user, User::query()->whereIn('id', $recentContacts->pluck('user_id'))->get(['id', 'name', 'employee_id']), $locale)
            ->map(fn ($p) => [...$p, 'conversation_id' => $recentContacts->firstWhere('user_id', $p['id'])?->conversation_id]);

        // An Eloquent collection either way: modelKeys() below does not exist on a
        // plain collection, and roles without announcements.view (investigator,
        // privacy officer) reach this page too.
        $announcements = $user->can('announcements.view')
            ? $this->audience->liveFor($user)->latest('published_at')->limit(5)->get()
            : new EloquentCollection;
        $receipts = AnnouncementReceipt::query()->where('user_id', $user->getKey())->whereIn('announcement_id', $announcements->modelKeys())->get()->keyBy('announcement_id');
        $pendingAcks = $user->can('announcements.view')
            ? $this->audience->liveFor($user)->where('acknowledgement_required', true)
                ->whereNotIn('announcements.id', AnnouncementReceipt::query()->select('announcement_id')->where('user_id', $user->getKey())->whereNotNull('acknowledged_at'))
                ->latest('published_at')->limit(10)->get()
            : new EloquentCollection;

        return Inertia::render('Communication/Index', [
            'totals' => $this->inbox->totals($user, fresh: true),
            'conversations' => $this->list->page($user, null, null, $locale, 8)['data'],
            'channels' => $this->list->page($user, ConversationType::Channel->value, null, $locale, 6)['data'],
            'contacts' => $contacts->values(),
            'announcements' => $announcements->map(fn ($a) => [
                'id' => $a->getKey(), 'title' => $a->localizedTitle($locale), 'priority' => $a->priority->value,
                'published_at' => $a->published_at?->toIso8601String(), 'acknowledgement_required' => $a->acknowledgement_required,
                'viewed' => $receipts->get($a->getKey())?->viewed_at !== null,
                'acknowledged' => $receipts->get($a->getKey())?->acknowledged_at !== null,
            ])->values(),
            'pendingAcknowledgements' => $pendingAcks->map(fn ($a) => [
                'id' => $a->getKey(), 'title' => $a->localizedTitle($locale), 'priority' => $a->priority->value, 'published_at' => $a->published_at?->toIso8601String(),
            ])->values(),
            'can' => [
                'message' => $user->can('messages.send'),
                'createGroup' => $user->can('groups.create') && $this->settings->allowEmployeeGroups(),
                'browseChannels' => $user->can('channels.view'),
                'createAnnouncement' => $user->can('announcements.create'),
                'tasks' => $user->can('communication.tasks.create') || CommunicationTask::query()->where('assigned_to', $user->getKey())->exists(),
                // Read from roles: the Super Admin bypass does not open moderation or investigations.
                'moderate' => $user->checkPermissionTo('communication.moderate'),
                'manageSettings' => $user->can('communication.settings.manage'),
                'investigations' => $user->checkPermissionTo('communication.break_glass.request') || $user->checkPermissionTo('communication.break_glass.approve') || $user->checkPermissionTo('communication.audit'),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->inbox->totals($request->user()));
    }
}
