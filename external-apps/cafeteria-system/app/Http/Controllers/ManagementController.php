<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\AuditLog;
use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaSetting;
use CafeteriaSystem\Models\CafeteriaUser;
use CafeteriaSystem\Services\CafeteriaSettingsRegistry;
use CafeteriaSystem\Services\EuisisApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Provider-scoped management of cafeterias, organization assignments and users.
 *
 * Every query is filtered by the acting user's provider, so one provider can
 * never see or modify another's data. Cafeteria managers are further confined
 * to the single cafeteria they are bound to.
 */
class ManagementController extends Controller
{
    // ── Cafeterias ──────────────────────────────────────────────────────

    public function cafeterias(Request $request): Response
    {
        $user = $this->manager($request);

        return Inertia::render('Management/Cafeterias', [
            'cafeterias' => Cafeteria::query()
                ->where('provider_id', $user->provider_id)
                ->whereIn('id', $user->accessibleCafeteriaIds())
                ->withCount(['organizationAssignments', 'users'])
                ->orderBy('name')
                ->get(),
            'provider' => $user->provider?->only(['id', 'code', 'name']),
            'can' => ['manage' => $user->isProviderAdmin()],
        ]);
    }

    public function storeCafeteria(Request $request): RedirectResponse
    {
        $user = $this->manager($request);
        abort_unless($user->isProviderAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', Rule::unique('cafeterias', 'code')],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'daily_capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $data['provider_id'] = $user->provider_id;
        $cafeteria = Cafeteria::query()->create($data);

        $this->audit($request, 'cafeteria_created', $cafeteria->code);

        return back()->with(['message' => __('cafeteria.cafeteriaCreated'), 'type' => 'success']);
    }

    // ── Organization assignments ────────────────────────────────────────

    public function assignments(Request $request): Response
    {
        $user = $this->manager($request);
        $cafeteriaIds = $user->accessibleCafeteriaIds();

        return Inertia::render('Management/Assignments', [
            'assignments' => CafeteriaOrganizationAssignment::query()
                ->with('cafeteria:id,name,code')
                ->whereIn('cafeteria_id', $cafeteriaIds)
                ->orderByDesc('effective_from')
                ->get(),
            'cafeterias' => Cafeteria::query()
                ->whereIn('id', $cafeteriaIds)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'can' => ['manage' => $user->canManage()],
        ]);
    }

    public function storeAssignment(Request $request): RedirectResponse
    {
        $user = $this->manager($request);
        abort_unless($user->canManage(), 403);

        $data = $request->validate([
            'cafeteria_id' => ['required', 'uuid'],
            'organization_code' => ['required', 'string', 'max:64'],
            'organization_name_snapshot' => ['required', 'string', 'max:255'],
            'organization_type_snapshot' => ['nullable', 'string', 'max:255'],
            'source_system_organization_id' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        // Never let a user assign an organization to someone else's cafeteria.
        abort_unless($user->canAccessCafeteria($data['cafeteria_id']), 403);

        $data['created_by'] = (string) $user->getKey();
        CafeteriaOrganizationAssignment::query()->create($data);

        $this->audit($request, 'organization_assigned', $data['organization_code']);

        return back()->with(['message' => __('cafeteria.organizationAssigned'), 'type' => 'success']);
    }

    public function destroyAssignment(Request $request, CafeteriaOrganizationAssignment $assignment): RedirectResponse
    {
        $user = $this->manager($request);

        abort_unless($user->canManage(), 403);
        abort_unless($user->canAccessCafeteria((string) $assignment->cafeteria_id), 403);

        $code = $assignment->organization_code;
        $assignment->delete();

        $this->audit($request, 'organization_unassigned', $code);

        return back()->with(['message' => __('cafeteria.organizationUnassigned'), 'type' => 'success']);
    }

    /**
     * Organization lookup proxied through the EUISIS API.
     *
     * The cafeteria has no access to the EUISIS database, so the directory is
     * fetched live. Failures return an empty list plus a reason, letting the
     * UI fall back to manual code entry rather than blocking the form.
     */
    public function organizationLookup(Request $request, EuisisApiClient $client): JsonResponse
    {
        $this->manager($request);

        $result = $client->organizations($request->string('search')->toString());

        return response()->json([
            'organizations' => $result['ok'] ? ($result['data']['data'] ?? []) : [],
            'error' => $result['ok'] ? null : $result['error'],
        ]);
    }

    // ── Cafeteria users ─────────────────────────────────────────────────

    public function users(Request $request): Response
    {
        $user = $this->manager($request);

        return Inertia::render('Management/Users', [
            'users' => CafeteriaUser::query()
                ->with('cafeteria:id,name,code')
                // Provider isolation: never another provider's staff.
                ->where('provider_id', $user->provider_id)
                ->when(! $user->isProviderAdmin(), fn ($query) => $query
                    ->whereIn('cafeteria_id', $user->accessibleCafeteriaIds()))
                ->orderBy('name')
                ->get(['id', 'cafeteria_id', 'name', 'email', 'role', 'status', 'last_login_at']),
            'cafeterias' => Cafeteria::query()
                ->whereIn('id', $user->accessibleCafeteriaIds())
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'roles' => CafeteriaUser::ROLES,
            'can' => ['manage' => $user->isProviderAdmin()],
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $user = $this->manager($request);
        abort_unless($user->isProviderAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('cafeteria_users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(CafeteriaUser::ROLES)],
            'cafeteria_id' => ['nullable', 'uuid'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        if ($data['cafeteria_id'] !== null) {
            abort_unless($user->canAccessCafeteria($data['cafeteria_id']), 403);
        }

        $data['provider_id'] = $user->provider_id;
        CafeteriaUser::query()->create($data);

        $this->audit($request, 'cafeteria_user_created', $data['email']);

        return back()->with(['message' => __('cafeteria.userCreated'), 'type' => 'success']);
    }

    // ── Providers ───────────────────────────────────────────────────────

    public function providers(Request $request): Response
    {
        $user = $this->manager($request);

        // A provider user only ever sees their own provider record.
        return Inertia::render('Management/Providers', [
            'providers' => CafeteriaProvider::query()
                ->where('id', $user->provider_id)
                ->withCount(['cafeterias', 'users'])
                ->get(),
            'can' => ['manage' => $user->isProviderAdmin()],
        ]);
    }

    public function updateProvider(Request $request): RedirectResponse
    {
        $user = $this->manager($request);
        abort_unless($user->isProviderAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'settlement_account' => ['nullable', 'string', 'max:255'],
        ]);

        $user->provider?->update($data);
        $this->audit($request, 'provider_updated', $user->provider?->code);

        return back()->with(['message' => __('cafeteria.providerUpdated'), 'type' => 'success']);
    }

    // ── Cafeteria settings ──────────────────────────────────────────────

    public function settings(Request $request): Response
    {
        $user = $this->manager($request);

        $stored = CafeteriaSetting::query()
            ->where('provider_id', $user->provider_id)
            ->pluck('value', 'key');

        // Merge stored values over the registry defaults so every tab renders
        // its full field set even before a provider has saved anything.
        $groups = [];

        foreach (CafeteriaSettingsRegistry::definition() as $tab => $fields) {
            foreach ($fields as $key => $definition) {
                $groups[$tab][] = [
                    'key' => $key,
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'options' => $definition['options'] ?? null,
                    'value' => $stored[$key] ?? (string) $definition['default'],
                ];
            }
        }

        return Inertia::render('Management/CafeteriaSettings', [
            'groups' => $groups,
            'tabs' => CafeteriaSettingsRegistry::tabs(),
            // "Provider Users" is a live roster, not a settings group.
            'providerUsers' => CafeteriaUser::query()
                ->with('cafeteria:id,name,code')
                ->where('provider_id', $user->provider_id)
                ->orderBy('name')
                ->get(['id', 'cafeteria_id', 'name', 'email', 'role', 'status', 'last_login_at']),
            'can' => ['manage' => $user->isProviderAdmin()],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $user = $this->manager($request);
        abort_unless($user->isProviderAdmin(), 403);

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', Rule::in(array_keys(CafeteriaSettingsRegistry::flat()))],
            'settings.*.value' => ['nullable', 'string', 'max:1024'],
        ]);

        foreach ($data['settings'] as $setting) {
            CafeteriaSetting::query()->updateOrCreate(
                ['provider_id' => $user->provider_id, 'key' => $setting['key']],
                ['value' => $setting['value'] ?? null],
            );
        }

        $this->audit($request, 'cafeteria_settings_updated');

        return back()->with(['message' => __('cafeteria.settingsUpdated'), 'type' => 'success']);
    }

    /**
     * The acting user, asserted to hold a management role.
     *
     * This previously returned the user without checking anything, so every
     * management page and write action was reachable by a scanner or report
     * viewer — the sidebar hid the links, but the routes answered 200.
     */
    private function manager(Request $request): CafeteriaUser
    {
        $user = $request->user('cafeteria');

        abort_unless($user !== null && $user->canManage(), 403);

        return $user;
    }

    private function audit(Request $request, string $event, ?string $description = null): void
    {
        AuditLog::query()->create([
            'cafeteria_user_id' => $request->user('cafeteria')?->getKey(),
            'event_type' => $event,
            'description' => $description,
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);
    }
}
