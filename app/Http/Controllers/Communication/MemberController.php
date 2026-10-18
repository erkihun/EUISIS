<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\AddConversationMembersAction;
use App\Actions\Communication\ChangeMemberRoleAction;
use App\Actions\Communication\LeaveConversationAction;
use App\Actions\Communication\RemoveConversationMemberAction;
use App\Enums\Communication\MemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\AddMembersRequest;
use App\Http\Requests\Communication\ChangeMemberRoleRequest;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use App\Services\Communication\CommunicationDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Adding, removing and promoting members, and leaving. */
class MemberController extends Controller
{
    /** Members a page at a time (by name), with an optional name search; for conversations too large to list at once. */
    public function index(Request $request, Conversation $conversation, CommunicationDirectory $directory): JsonResponse
    {
        $this->visible($request, $conversation);
        $term = trim($request->string('q')->toString());
        $cursor = $request->string('cursor')->toString();
        $query = ConversationMember::query()->where('conversation_members.conversation_id', $conversation->getKey())->whereNull('conversation_members.left_at')
            ->join('users', 'users.id', '=', 'conversation_members.user_id')
            ->select(['conversation_members.user_id', 'conversation_members.role', 'conversation_members.source', 'users.name as user_name'])
            ->orderBy('users.name')->orderBy('conversation_members.user_id');
        if (mb_strlen($term) >= 2) {
            $query->where('users.name', ci_like_operator(), '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%');
        }
        if ($cursor !== '' && ($decoded = json_decode((string) base64_decode($cursor, true), true)) && count($decoded) === 2) {
            [$name, $id] = $decoded;
            $query->where(fn ($q) => $q->where('users.name', '>', $name)->orWhere(fn ($e) => $e->where('users.name', $name)->where('conversation_members.user_id', '>', (int) $id)));
        }
        $rows = $query->limit(51)->get();
        $more = $rows->count() > 50;
        $rows = $rows->take(50);
        $people = $directory->present($request->user(), User::query()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'employee_id']), app()->getLocale())->keyBy('id');
        $last = $rows->last();

        return response()->json([
            'data' => $rows->map(fn ($m) => [
                ...($people->get($m->user_id) ?? ['id' => $m->user_id, 'name' => $m->user_name]),
                'role' => $m->role->value, 'source' => $m->source->value,
                'last_read_message_id' => null, 'last_delivered_message_id' => null,
            ])->values(),
            'next_cursor' => $more && $last ? base64_encode((string) json_encode([$last->user_name, $last->user_id])) : null,
        ]);
    }

    public function store(AddMembersRequest $request, Conversation $conversation, AddConversationMembersAction $add): JsonResponse
    {
        $this->visible($request, $conversation);
        $added = $add->execute($request->user(), $conversation, array_map('intval', $request->validated('user_ids')));

        return response()->json(['added' => count($added)]);
    }

    public function destroy(Request $request, Conversation $conversation, User $user, RemoveConversationMemberAction $remove): JsonResponse
    {
        $this->visible($request, $conversation);
        $remove->execute($request->user(), $conversation, $user);

        return response()->json(['ok' => true]);
    }

    public function role(ChangeMemberRoleRequest $request, Conversation $conversation, User $user, ChangeMemberRoleAction $change): JsonResponse
    {
        $this->visible($request, $conversation);
        $change->execute($request->user(), $conversation, $user, MemberRole::from($request->validated('role')));

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, Conversation $conversation, LeaveConversationAction $leave): JsonResponse
    {
        $this->visible($request, $conversation);
        $leave->execute($request->user(), $conversation);

        return response()->json(['ok' => true]);
    }

    /** Members, or a scoped channel manager; a guessed id of anything else is a plain 404. */
    private function visible(Request $request, Conversation $conversation): void
    {
        abort_unless(Gate::forUser($request->user())->any(['view', 'manageMembers'], $conversation), 404);
    }
}
