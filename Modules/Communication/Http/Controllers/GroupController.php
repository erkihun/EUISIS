<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Application\Actions\ArchiveConversationAction;
use Modules\Communication\Application\Actions\CreateGroupAction;
use Modules\Communication\Application\Actions\UpdateConversationAction;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Http\Requests\StoreGroupRequest;
use Modules\Communication\Http\Requests\UpdateConversationRequest;

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
