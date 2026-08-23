<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only organization directory for approved external applications.
 *
 * Exists so an integration (e.g. the Cafeteria System) can let an administrator
 * pick a real organization instead of typing a code by hand. Returns only
 * public identifying fields — no employees, no counts, no internal ids beyond
 * the reference an integration needs to store alongside the code.
 */
class OrganizationDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $organizations = Organization::query()
            ->with('type:id,code,name_en,name_am')
            // Active organizations only: an integration should never be able to
            // assign service to an archived or inactive structure.
            ->where('status', OrganizationStatus::Active->value)
            ->whereNull('deleted_at')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(fn ($inner) => $inner
                    ->where('code', ci_like_operator(), $like)
                    ->orWhere('name_en', ci_like_operator(), $like)
                    ->orWhere('name_am', ci_like_operator(), $like));
            })
            ->orderBy('name_en')
            ->limit(min((int) $request->integer('limit', 100), 500))
            ->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status']);

        return response()->json([
            'data' => $organizations->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'status' => $organization->status instanceof \BackedEnum
                    ? $organization->status->value
                    : (string) $organization->status,
                'type' => $organization->type === null ? null : [
                    'code' => $organization->type->code,
                    'name_en' => $organization->type->name_en,
                    'name_am' => $organization->type->name_am,
                ],
            ])->all(),
        ]);
    }
}
