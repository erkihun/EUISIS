<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Backbone\Contracts\OrganizationDirectory;
use App\Backbone\Data\OrganizationData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization search for the transport provider and route forms: active
 * organizations within the caller's scope, matched by code or name, never a
 * full list. Read through the Core OrganizationDirectory (Stage 3), so the
 * scope rule is the canonical one.
 */
class TransportOrganizationLookupController extends Controller
{
    private const LIMIT = 20;

    /** Anyone who may set a provider's or a route's organization. */
    private const PERMISSIONS = ['transport-providers.create', 'transport-providers.update', 'transport-routes.create', 'transport-routes.update'];

    public function __invoke(Request $request, OrganizationDirectory $organizations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(self::PERMISSIONS), 403);

        $term = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => array_map(fn (OrganizationData $organization): array => [
            'id' => $organization->organizationUuid,
            'code' => $organization->code,
            'name_en' => $organization->nameEn,
            'name_am' => $organization->nameAm,
        ], $organizations->searchActiveFor($user, $term, self::LIMIT))]);
    }
}
