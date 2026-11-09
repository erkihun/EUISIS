<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Application\Actions\DeleteMessageAction;
use Modules\Communication\Application\Actions\EditMessageAction;
use Modules\Communication\Application\Actions\ForwardMessageAction;
use Modules\Communication\Application\Actions\PinMessageAction;
use Modules\Communication\Application\Actions\SendMessageAction;
use Modules\Communication\Application\Actions\ToggleReactionAction;
use Modules\Communication\Application\Presenters\MessageResource;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\Message;
use Modules\Communication\Http\Requests\ForwardMessageRequest;
use Modules\Communication\Http\Requests\ReactionRequest;
use Modules\Communication\Http\Requests\StoreMessageRequest;
use Modules\Communication\Http\Requests\UpdateMessageRequest;

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
