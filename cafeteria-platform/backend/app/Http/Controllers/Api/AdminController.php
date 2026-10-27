<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\MultiFactor;
use App\Actions\SaveUser;
use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Models\User;
use App\Services\IntegrityChecks;
use App\Services\OrganizationScope;
use App\Services\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Administrator screens: Core accounts and the data-quality checks. */
class AdminController extends Controller
{
    public function users(Request $request, OrganizationScope $scope): JsonResponse
    {
        $term = trim((string) $request->input('search', ''));
        $query = User::query()->leftJoin('organizations', 'organizations.id', '=', 'users.scope_organization_id')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('users.name', '%'.$term.'%')->orWhereLike('users.email', '%'.$term.'%')))
            ->orderBy('users.name');

        $scope->apply($query, $request->user(), 'users.scope_organization_id');

        return response()->json($query->paginate(25, ['users.id', 'users.name', 'users.email', 'users.role', 'users.scope_type', 'users.scope_organization_id', 'users.is_active', 'organizations.name_en as scope_organization_name'])->withQueryString());
    }

    public function saveUser(Request $request, SaveUser $save, ?User $user = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::exists('core_roles', 'key')],
            'scope_type' => ['nullable', Rule::in([User::SCOPE_CITYWIDE, ...User::ORGANIZATION_SCOPES])],
            'scope_organization_id' => ['nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $result = $save->execute($user, $data, $request->user());

        return response()->json([
            'data' => $result['user']->only(['id', 'name', 'email', 'role', 'scope_type', 'scope_organization_id', 'is_active']),
            // Shown once to the administrator who hands it over; never stored in clear.
            'one_time_password' => $result['password'],
        ], $user === null ? 201 : 200);
    }

    /** A lost authenticator: the holder enrols again at the next sign-in; every sign-in of the account ends (audited as mfa.reset). */
    public function resetMfa(Request $request, User $user, SaveUser $save, MultiFactor $mfa): JsonResponse
    {
        abort_unless($save->manages($request->user(), $user), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $mfa->reset($user, $request->user(), $data['reason']);

        return response()->json(['reset' => true]);
    }

    public function resetPassword(Request $request, User $user, SaveUser $save): JsonResponse
    {
        return response()->json(['one_time_password' => $save->resetPassword($user, $request->user())]);
    }

    /**
     * Each check's count and its first findings (ids for drill-down). A card
     * finding also names its holder, whose page lists the card.
     */
    public function dataQuality(IntegrityChecks $checks): JsonResponse
    {
        $counts = $checks->counts();
        $results = [];
        foreach (IntegrityChecks::SUBJECTS as $key => [, $kind]) {
            $samples = $counts[$key] > 0 ? $checks->samples($key) : [];
            $holders = $kind === 'card' ? IdCard::query()->whereIn('id', $samples)->pluck('employee_id', 'id') : collect();
            $results[] = [
                'key' => $key,
                'kind' => $kind,
                'count' => $counts[$key],
                'samples' => $samples,
                'records' => array_map(fn (string $id): array => ['id' => $id, 'employee_id' => $holders[$id] ?? null], $samples),
            ];
        }

        return response()->json(['checks' => $results]);
    }

    /** Operational health from real measurements (SystemHealth); city-wide administrators only. */
    public function systemHealth(SystemHealth $health): JsonResponse
    {
        $checks = $health->checks();
        $statuses = array_column($checks, 'status');

        return response()->json([
            'status' => in_array('error', $statuses, true) ? 'error' : (in_array('warning', $statuses, true) ? 'warning' : 'ok'),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ]);
    }
}
