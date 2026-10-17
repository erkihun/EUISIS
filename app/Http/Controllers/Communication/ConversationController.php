<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\MarkConversationReadAction;
use App\Actions\Communication\StartDirectConversationAction;
use App\Enums\Communication\ConversationType;
use App\Events\Communication\UserTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\MarkReadRequest;
use App\Http\Requests\Communication\MuteConversationRequest;
use App\Http\Requests\Communication\StartDirectConversationRequest;
use App\Http\Resources\Communication\MessageResource;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\Communication\ConversationPin;
use App\Models\Communication\Message;
use App\Models\Communication\MessageAttachment;
use App\Models\User;
use App\Services\Communication\CommunicationAccess;
use App\Services\Communication\CommunicationBroadcaster;
use App\Services\Communication\CommunicationDirectory;
use App\Services\Communication\CommunicationSettings;
use App\Services\Communication\ConversationList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Conversation JSON endpoints for the chat workspace. Every endpoint resolves
 * the conversation and checks membership through the policy; a conversation
 * the user is not in answers 404 (never 403), so ids cannot be probed.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationList $list,
        private readonly CommunicationAccess $access,
        private readonly CommunicationDirectory $directory,
        private readonly CommunicationSettings $settings,
        private readonly CommunicationBroadcaster $broadcaster,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $search = $request->query('q');

        return response()->json($this->list->page($request->user(), is_string($type) ? $type : null, $request->query('cursor'), app()->getLocale(),
            ConversationList::PAGE, is_string($search) ? $search : null));
    }

    public function store(StartDirectConversationRequest $request, StartDirectConversationAction $start): JsonResponse
    {
        $other = User::query()->findOrFail($request->integer('user_id'));
        $conversation = $start->execute($request->user(), $other);

        return response()->json(['id' => $conversation->getKey()], 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $conversation);
        $member = $this->access->membership($user, $conversation);
        $locale = app()->getLocale();
        $row = $this->list->present($user, collect([$this->membershipRow($user, $conversation)]), $locale)[0];
        $small = $conversation->activeMembers()->count() <= (int) config('communication.receipts_max_members', 25);

        $members = $small
            ? $conversation->activeMembers()->with('user:id,name,employee_id')->get()
            : $conversation->activeMembers()->whereIn('role', ['owner', 'admin'])->with('user:id,name,employee_id')->limit(50)->get();
        $people = $this->directory->present($user, $members->pluck('user')->filter(), $locale)->keyBy('id');

        return response()->json([
            ...$row,
            'description' => $conversation->description,
            'member_count' => $conversation->activeMembers()->count(),
            'members' => $members->map(fn (ConversationMember $m) => [
                ...($people->get($m->user_id) ?? ['id' => $m->user_id, 'name' => $m->user?->name]),
                'role' => $m->role->value,
                'source' => $m->source->value,
                // Read state is shared only in small conversations, and only when the system allows receipts.
                'last_read_message_id' => $small && $this->settings->allowReadReceipts() ? $m->last_read_message_id : null,
                'last_delivered_message_id' => $small && $this->settings->allowReadReceipts() ? $m->last_delivered_message_id : null,
            ])->values(),
            'members_complete' => $small,
            'my_last_read_message_id' => $member?->last_read_message_id,
            'can' => [
                'post' => Gate::forUser($user)->allows('sendMessage', $conversation),
                'manageMembers' => Gate::forUser($user)->allows('manageMembers', $conversation),
                'update' => Gate::forUser($user)->allows('update', $conversation),
                'changeRoles' => Gate::forUser($user)->allows('changeRoles', $conversation),
                'archive' => Gate::forUser($user)->allows('archive', $conversation),
                'leave' => Gate::forUser($user)->allows('leave', $conversation),
                'pin' => Gate::forUser($user)->allows('pin', $conversation),
                'mentionEveryone' => $conversation->type !== ConversationType::Direct && (bool) $member?->role->canManage(),
            ],
        ]);
    }

    /** Newest first, keyset-paged by message id (UUIDv7 is time-ordered). */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->visible($request->user(), $conversation);
        $page = (int) config('communication.page_size', 40);
        $before = $request->query('before');

        $rows = Message::query()->withTrashed()->where('conversation_id', $conversation->getKey())
            ->when(is_string($before) && $before !== '', fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit($page + 1)
            ->with(['sender:id,name', 'attachments', 'reactions', 'mentions.mentionedUser:id,name', 'parent.sender:id,name'])
            ->get();
        $more = $rows->count() > $page;
        $rows = $rows->take($page);

        return response()->json([
            'data' => MessageResource::collection($rows)->resolve(),
            'next_before' => $more ? $rows->last()?->getKey() : null,
        ]);
    }

    public function read(MarkReadRequest $request, Conversation $conversation, MarkConversationReadAction $read): JsonResponse
    {
        $this->visible($request->user(), $conversation);

        return response()->json(['last_read_message_id' => $read->execute($conversation, $request->user(), $request->input('message_id'))]);
    }

    /** Throttled, never stored; broadcast to the other members only. */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $conversation);
        if ($this->access->canPost($user, $conversation)) {
            $key = "communication-typing:{$user->getKey()}:{$conversation->getKey()}";
            if (! RateLimiter::tooManyAttempts($key, 1)) {
                RateLimiter::hit($key, 3);
                $this->broadcaster->send(new UserTyping($conversation->getKey(), (int) $user->getKey(), (string) $user->name));
            }
        }

        return response()->json(['ok' => true]);
    }

    public function mute(MuteConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $conversation);
        $until = $request->boolean('mute') ? ($request->filled('minutes') ? now()->addMinutes($request->integer('minutes')) : now()->addYears(50)) : null;
        ConversationMember::query()->where('conversation_id', $conversation->getKey())->where('user_id', $user->getKey())->update(['muted_until' => $until]);
        app(\App\Services\Communication\CommunicationInbox::class)->forgetTotals($user->getKey());

        return response()->json(['muted_until' => $until?->toIso8601String()]);
    }

    public function pins(Request $request, Conversation $conversation): JsonResponse
    {
        $this->visible($request->user(), $conversation);
        $pins = ConversationPin::query()->where('conversation_id', $conversation->getKey())->latest('pinned_at')
            ->with(['message.sender:id,name', 'message.attachments', 'message.reactions', 'message.mentions.mentionedUser:id,name', 'pinner:id,name'])->limit(50)->get();

        return response()->json(['data' => $pins->map(fn (ConversationPin $p) => [
            'pinned_at' => $p->pinned_at?->toIso8601String(),
            'pinned_by' => $p->pinner?->name,
            'message' => (new MessageResource($p->message))->resolve(),
        ])->values()]);
    }

    /** Shared files, newest first (keyset by id). */
    public function files(Request $request, Conversation $conversation): JsonResponse
    {
        $this->visible($request->user(), $conversation);
        $before = $request->query('before');
        $rows = MessageAttachment::query()->where('conversation_id', $conversation->getKey())->whereNotNull('message_id')
            ->whereIn('message_id', Message::query()->select('id')->where('conversation_id', $conversation->getKey()))
            ->when(is_string($before) && $before !== '', fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(31)->with('uploader:id,name')->get();
        $more = $rows->count() > 30;
        $rows = $rows->take(30);

        return response()->json([
            'data' => $rows->map(fn (MessageAttachment $a) => [...MessageResource::attachment($a), 'uploaded_by' => $a->uploader?->name, 'created_at' => $a->created_at?->toIso8601String(), 'message_id' => $a->message_id])->values(),
            'next_before' => $more ? $rows->last()?->getKey() : null,
        ]);
    }

    private function visible(User $user, Conversation $conversation): void
    {
        abort_unless(Gate::forUser($user)->allows('view', $conversation), 404);
    }

    private function membershipRow(User $user, Conversation $conversation): ConversationMember
    {
        return app(\App\Services\Communication\CommunicationInbox::class)->memberships($user)
            ->join('conversations', 'conversations.id', '=', 'conversation_members.conversation_id')
            ->where('conversation_members.conversation_id', $conversation->getKey())
            ->addSelect(['conversations.type as conversation_type', 'conversations.title as conversation_title',
                'conversations.last_message_id as conversation_last_message_id', 'conversations.last_message_at as conversation_last_message_at',
                'conversations.avatar_path as conversation_avatar'])
            ->firstOrFail();
    }
}
