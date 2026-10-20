<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization search for the transport provider and route forms: active
 * organizations within the caller's scope, matched by code or name, never a
 * full list.
 */
class TransportOrganizationLookupController extends Controller
{
    private const LIMIT = 20;

    /** Anyone who may set a provider's or a route's organization. */
    private const PERMISSIONS = ['transport-providers.create', 'transport-providers.update', 'transport-routes.create', 'transport-routes.update'];

    public function __invoke(Request $request, OrganizationScopeService $scope): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(self::PERMISSIONS), 403);

        $term = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = ci_like_operator();
        $contains = '%'.addcslashes($term, '%_\\').'%';

        $query = Organization::query()
            ->where('status', OrganizationStatus::Active)
            ->where(fn (Builder $q) => $q
                ->where('code', $like, addcslashes($term, '%_\\').'%')
                ->orWhere('name_en', $like, $contains)
                ->orWhere('name_am', $like, $contains));

        if (! $scope->isUnrestricted($user)) {
            $query->whereIn('id', $scope->allowedOrganizationIds($user));
        }

        return response()->json(['data' => $query->orderBy('name_en')->limit(self::LIMIT)->get(['id', 'code', 'name_en', 'name_am'])]);
    }
}
