<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\DeleteMessageAction;
use App\Actions\Communication\EditMessageAction;
use App\Actions\Communication\ForwardMessageAction;
use App\Actions\Communication\PinMessageAction;
use App\Actions\Communication\SendMessageAction;
use App\Actions\Communication\ToggleReactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ForwardMessageRequest;
use App\Http\Requests\Communication\ReactionRequest;
use App\Http\Requests\Communication\StoreMessageRequest;
use App\Http\Requests\Communication\UpdateMessageRequest;
use App\Http\Resources\Communication\MessageResource;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Message JSON endpoints. Thin: each delegates to one action; ids outside the user's conversations answer 404. */
class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, Conversation $conversation, SendMessageAction $send): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $conversation), 404);
        $message = $send->execute($conversation, $request->user(), $request->validated());

        return response()->json(['data' => (new MessageResource($message))->resolve()], 201);
    }

    public function update(UpdateMessageRequest $request, Message $message, EditMessageAction $edit): JsonResponse
    {
        $this->visible($request->user(), $message);

        return response()->json(['data' => (new MessageResource($edit->execute($message, $request->user(), (string) $request->input('body', ''))))->resolve()]);
    }

    public function destroy(Request $request, Message $message, DeleteMessageAction $delete): JsonResponse
    {
        $this->visible($request->user(), $message);
        $delete->execute($message, $request->user());

        return response()->json(['deleted' => true]);
    }

    public function react(ReactionRequest $request, Message $message, ToggleReactionAction $toggle): JsonResponse
    {
        $this->visible($request->user(), $message);

        return response()->json(['reactions' => $toggle->execute($message, $request->user(), (string) $request->input('emoji'), $request->isMethod('post'))]);
    }

    public function pin(Request $request, Message $message, PinMessageAction $pin): JsonResponse
    {
        $this->visible($request->user(), $message);
        $pin->execute($message, $request->user(), $request->isMethod('post'));

        return response()->json(['pinned' => $request->isMethod('post')]);
    }

    public function forward(ForwardMessageRequest $request, Message $message, ForwardMessageAction $forward): JsonResponse
    {
        $this->visible($request->user(), $message);
        $sent = $forward->execute($message, $request->user(), $request->input('conversation_ids', []));

        return response()->json(['forwarded' => count($sent)], 201);
    }

    private function visible(User $user, Message $message): void
    {
        abort_unless(Gate::forUser($user)->allows('view', $message), 404);
    }
}
