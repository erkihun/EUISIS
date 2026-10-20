<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\ArchiveConversationAction;
use App\Actions\Communication\CreateGroupAction;
use App\Actions\Communication\UpdateConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreGroupRequest;
use App\Http\Requests\Communication\UpdateConversationRequest;
use App\Models\Communication\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Creating groups, and renaming or archiving a group or channel. */
class GroupController extends Controller
{
    public function store(StoreGroupRequest $request, CreateGroupAction $create): JsonResponse
    {
        $conversation = $create->execute($request->user(), $request->validated());

        return response()->json(['id' => $conversation->getKey()], 201);
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation, UpdateConversationAction $update): JsonResponse
    {
        $this->visible($request, $conversation);
        $update->execute($request->user(), $conversation, $request->validated());

        return response()->json(['ok' => true]);
    }

    public function archive(Request $request, Conversation $conversation, ArchiveConversationAction $archive): JsonResponse
    {
        // Scoped managers may archive a group they cannot read; anyone else gets the usual 404.
        abort_unless(Gate::forUser($request->user())->any(['view', 'archive'], $conversation)
            || ($conversation->channel && Gate::forUser($request->user())->allows('archive', $conversation->channel)), 404);
        $archive->execute($request->user(), $conversation, $request->string('reason')->limit(500)->toString() ?: null);

        return response()->json(['ok' => true]);
    }

    private function visible(Request $request, Conversation $conversation): void
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $conversation)
            || ($conversation->channel && Gate::forUser($request->user())->allows('update', $conversation->channel)), 404);
    }
}
