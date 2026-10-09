<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\RestoreRecycleBinRecordRequest;
use App\Http\Resources\RecycleBinRecordResource;
use App\Services\RecycleBin\RecycleBinRegistry;
use App\Services\RecycleBin\RecycleBinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecycleBinController extends Controller
{
    public function index(Request $request, RecycleBinService $recycleBinService, RecycleBinRegistry $registry): Response
    {
        abort_unless($request->user()?->can('recycle-bin.view'), 403);

        $records = $recycleBinService->records($request);

        return Inertia::render('RecycleBin/Index', [
            'records' => [
                'data' => RecycleBinRecordResource::collection($records->getCollection())->resolve($request),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                ],
            ],
            'filters' => $request->only(['type', 'search', 'deleted_from', 'deleted_to', 'deleted_by']),
            'types' => collect($registry->supportedTypes())
                ->filter(fn (array $definition): bool => $request->user()?->can($definition['view_deleted_permission']) ?? false)
                ->map(fn (array $definition, string $type): array => [
                    'value' => $type,
                    'label_key' => $definition['label_key'],
                ])
                ->values(),
            'can' => [
                'restore' => $request->user()?->can('recycle-bin.restore') ?? false,
                'forceDelete' => $request->user()?->can('recycle-bin.forceDelete') ?? false,
            ],
        ]);
    }

    public function restore(
        RestoreRecycleBinRecordRequest $request,
        string $type,
        string $id,
        RecycleBinService $recycleBinService,
    ): RedirectResponse {
        $recycleBinService->restore($type, $id, $request->user(), $request);

        return back()->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }

    public function forceDelete(
        Request $request,
        string $type,
        string $id,
        RecycleBinService $recycleBinService,
    ): RedirectResponse {
        /*
         * Permanent destruction is gated on its own permission, not on
         * `recycle-bin.restore`.
         *
         * `recycle-bin.forceDelete` has always existed in the permission
         * catalog, but this endpoint checked the restore permission instead —
         * so a role meant only to RECOVER records could irreversibly destroy
         * them. Every role that holds restore today also holds forceDelete, so
         * this tightening removes no capability from anyone currently.
         */
        abort_unless($request->user()?->can('recycle-bin.forceDelete'), 403);

        $recycleBinService->forceDelete($type, $id, $request->user(), $request);

        return back()->with('flash', ['message' => __('recycle-bin.deleted_successfully'), 'type' => 'success']);
    }
}
