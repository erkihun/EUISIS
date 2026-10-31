<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Communication\Application\Actions\BlockUserAction;
use Modules\Communication\Domain\Models\CommunicationBlock;

/** The people someone has blocked; only they see this list. */
class BlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = CommunicationBlock::query()->where('blocker_id', $request->user()->getKey())
            ->join('users', 'users.id', '=', 'communication_blocks.blocked_id')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'communication_blocks.created_at']);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'blocked_at' => $r->created_at])->values()]);
    }

    public function store(Request $request, BlockUserAction $block): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $target = User::query()->find((int) $data['user_id']);
        abort_if($target === null, 422, __('communication.errors.recipient_unavailable'));
        $block->block($request->user(), $target);

        return response()->json(['blocked' => true]);
    }

    public function destroy(Request $request, User $user, BlockUserAction $block): JsonResponse
    {
        $block->unblock($request->user(), $user);

        return response()->json(['blocked' => false]);
    }
}
