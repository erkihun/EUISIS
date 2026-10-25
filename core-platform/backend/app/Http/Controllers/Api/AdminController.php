<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SaveUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\IntegrityChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Administrator screens: Core accounts and the data-quality checks. */
class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('search', ''));
        $query = User::query()->leftJoin('organizations', 'organizations.id', '=', 'users.scope_organization_id')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('users.name', '%'.$term.'%')->orWhereLike('users.email', '%'.$term.'%')))
            ->orderBy('users.name');

        return response()->json($query->paginate(25, ['users.id', 'users.name', 'users.email', 'users.role', 'users.scope_organization_id', 'users.is_active', 'organizations.name_en as scope_organization_name'])->withQueryString());
    }

    public function saveUser(Request $request, SaveUser $save, ?User $user = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(User::ROLES)],
            'scope_organization_id' => ['nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $result = $save->execute($user, $data, $request->user());

        return response()->json([
            'data' => $result['user']->only(['id', 'name', 'email', 'role', 'scope_organization_id', 'is_active']),
            // Shown once to the administrator who hands it over; never stored in clear.
            'one_time_password' => $result['password'],
        ], $user === null ? 201 : 200);
    }

    public function resetPassword(Request $request, User $user, SaveUser $save): JsonResponse
    {
        return response()->json(['one_time_password' => $save->resetPassword($user, $request->user())]);
    }

    /** Each check's count and its first findings (ids for drill-down). */
    public function dataQuality(IntegrityChecks $checks): JsonResponse
    {
        $counts = $checks->counts();
        $results = [];
        foreach (IntegrityChecks::SUBJECTS as $key => [, $kind]) {
            $results[] = ['key' => $key, 'kind' => $kind, 'count' => $counts[$key], 'samples' => $counts[$key] > 0 ? $checks->samples($key) : []];
        }

        return response()->json(['checks' => $results]);
    }
}
