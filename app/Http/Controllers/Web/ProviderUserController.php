<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderUsers\SaveProviderUserRequest;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\ServiceType;
use App\Models\User;
use App\Security\Passwords\PasswordPolicy;
use App\Services\ProviderPortal\ProviderUserAccountService;
use App\Support\ProviderPortal\ProviderUserPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /provider-users: the accounts external providers (cafeteria, transport...)
 * use to sign in at /provider/portal/login.
 */
class ProviderUserController extends Controller
{
    private const STATUSES = ['active', 'inactive', 'suspended'];

    public function __construct(private readonly ProviderUserAccountService $accounts) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProviderUser::class);

        $filters = [
            'search' => trim($request->string('search')->limit(100, '')->toString()),
            'provider_id' => $this->uuidOrNull($request->string('provider_id')->toString()),
            'status' => in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null,
            'role' => in_array($request->input('role'), ProviderUserPermissionCatalog::ROLES, true) ? $request->input('role') : null,
            'service' => $request->string('service')->limit(50, '')->toString() ?: null,
            'trashed' => $request->input('trashed') === 'only' ? 'only' : null,
        ];

        $accounts = ProviderUser::query()
            ->with(['provider' => fn ($query) => $query->withTrashed()->with('providerType:id,code,name_en,name_am')])
            ->when($filters['trashed'] === 'only', fn (Builder $query) => $query->onlyTrashed())
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $like = '%'.$filters['search'].'%';
                $query->where(fn (Builder $nested) => $nested
                    ->where('name', ci_like_operator(), $like)
                    ->orWhere('email', ci_like_operator(), $like)
                    ->orWhere('username', ci_like_operator(), $like)
                    ->orWhere('phone_number', ci_like_operator(), $like)
                    ->orWhereHas('provider', fn (Builder $provider) => $provider
                        ->where('name_en', ci_like_operator(), $like)
                        ->orWhere('name_am', ci_like_operator(), $like)
                        ->orWhere('provider_code', ci_like_operator(), $like)));
            })
            ->when($filters['provider_id'], fn (Builder $query, string $id) => $query->where('provider_id', $id))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['role'], fn (Builder $query, string $role) => $query->where('provider_role', $role))
            ->when($filters['service'], fn (Builder $query, string $code) => $query->whereHas('provider.services', fn (Builder $service) => $service
                ->where('status', 'active')
                ->whereHas('serviceType', fn (Builder $type) => $type->where('code', $code))))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $user = $request->user();
        $statusCounts = ProviderUser::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('ProviderUsers/Index', [
            'rows' => $accounts->getCollection()->map(fn (ProviderUser $account): array => [
                ...$this->summary($account),
                'can' => $this->abilities($user, $account),
            ])->all(),
            'meta' => ['currentPage' => $accounts->currentPage(), 'lastPage' => $accounts->lastPage(), 'total' => $accounts->total(), 'perPage' => $accounts->perPage()],
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'stats' => [
                'total' => (int) $statusCounts->sum(),
                'active' => (int) ($statusCounts['active'] ?? 0),
                'suspended' => (int) ($statusCounts['suspended'] ?? 0),
                'inactive' => (int) ($statusCounts['inactive'] ?? 0),
                'must_change_password' => ProviderUser::query()->where('must_change_password', true)->count(),
                'deleted' => ProviderUser::onlyTrashed()->count(),
            ],
            'providers' => Provider::query()->orderBy('name_en')->get(['id', 'provider_code', 'name_en', 'name_am'])
                ->map(fn (Provider $provider): array => ['id' => $provider->id, 'code' => $provider->provider_code, 'name_en' => $provider->name_en, 'name_am' => $provider->name_am])
                ->all(),
            'services' => $this->serviceOptions(),
            'can' => ['create' => $user->can('create', ProviderUser::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', ProviderUser::class);

        return Inertia::render('ProviderUsers/Form', [
            'account' => null,
            'providers' => $this->providerOptions(),
            'services' => $this->serviceOptions(),
            'roles' => ProviderUserPermissionCatalog::ROLES,
            'defaults' => ['provider_id' => $this->uuidOrNull($request->string('provider_id')->toString())],
        ]);
    }

    public function store(SaveProviderUserRequest $request): RedirectResponse
    {
        [$account, $temporary] = $this->accounts->create($request->validated(), $request->user(), $request);

        return redirect()->route('provider-users.show', $account)->with('flash', array_filter([
            'message' => __('provider-users.created').($temporary ? ' '.__('password-policy.temporary_password_created') : ''),
            'type' => 'success',
            'temporary_password' => $temporary,
        ]));
    }

    public function show(Request $request, ProviderUser $providerUser): Response
    {
        $this->authorize('view', $providerUser);

        $providerUser->load([
            'provider' => fn ($query) => $query->withTrashed()->with(['providerType:id,code,name_en,name_am', 'services.serviceType:id,code,name_en,name_am']),
            'servicePermissions:id,provider_user_id,permission_key,is_allowed,granted_at',
        ]);
        $people = User::query()
            ->whereIn('id', array_filter([$providerUser->created_by, $providerUser->updated_by, $providerUser->suspended_by]))
            ->pluck('name', 'id');

        $history = AuditLog::query()
            ->where('auditable_type', ProviderUser::class)
            ->where('auditable_id', $providerUser->id)
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'event_type', 'actor_user_id', 'reason', 'created_at']);
        $actors = User::query()->whereIn('id', $history->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('ProviderUsers/Show', [
            'account' => [
                ...$this->summary($providerUser),
                'last_login_ip' => $providerUser->last_login_ip,
                'suspended_at' => $providerUser->suspended_at?->toIso8601String(),
                'suspension_reason' => $providerUser->suspension_reason,
                'suspended_by' => $people[$providerUser->suspended_by] ?? null,
                'created_at' => $providerUser->created_at?->toIso8601String(),
                'created_by' => $people[$providerUser->created_by] ?? null,
                'updated_at' => $providerUser->updated_at?->toIso8601String(),
                'updated_by' => $people[$providerUser->updated_by] ?? null,
                // What the portal checks at sign-in (a deleted provider counts as none).
                'can_sign_in' => ! $providerUser->trashed()
                    && $providerUser->isActive()
                    && $providerUser->isPortalEnabled()
                    && $providerUser->provider !== null
                    && ! $providerUser->provider->trashed()
                    && $providerUser->provider->status === 'active',
                'provider_services' => $providerUser->provider
                    ? $providerUser->provider->services->where('status', 'active')->map(fn ($service): array => [
                        'code' => $service->serviceType?->code,
                        'name_en' => $service->serviceType?->name_en,
                        'name_am' => $service->serviceType?->name_am,
                    ])->values()->all()
                    : [],
                'service_permissions' => $providerUser->servicePermissions->where('is_allowed', true)->pluck('permission_key')->values()->all(),
            ],
            'history' => $history->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event_type->value,
                'actor' => $actors[$log->actor_user_id] ?? null,
                'reason' => $log->reason,
                'at' => $log->created_at?->toIso8601String(),
            ])->all(),
            'can' => $this->abilities($request->user(), $providerUser),
        ]);
    }

    public function edit(ProviderUser $providerUser): Response
    {
        $this->authorize('update', $providerUser);

        $providerUser->load('servicePermissions:id,provider_user_id,permission_key,is_allowed');

        return Inertia::render('ProviderUsers/Form', [
            'account' => [
                'id' => $providerUser->id,
                'provider_id' => $providerUser->provider_id,
                'name' => $providerUser->name,
                'email' => $providerUser->email,
                'username' => $providerUser->username,
                'phone_number' => $providerUser->phone_number,
                'provider_role' => $providerUser->provider_role,
                'status' => $providerUser->status,
                'portal_enabled' => (bool) $providerUser->portal_enabled,
                'service_permissions' => $providerUser->servicePermissions->where('is_allowed', true)->pluck('permission_key')->values()->all(),
            ],
            'providers' => $this->providerOptions($providerUser->provider_id),
            'services' => $this->serviceOptions(),
            'roles' => ProviderUserPermissionCatalog::ROLES,
            'defaults' => ['provider_id' => $providerUser->provider_id],
        ]);
    }

    public function update(SaveProviderUserRequest $request, ProviderUser $providerUser): RedirectResponse
    {
        $this->accounts->update($providerUser, $request->validated(), $request->user(), $request);

        return redirect()->route('provider-users.show', $providerUser)
            ->with('flash', ['message' => __('provider-users.updated'), 'type' => 'success']);
    }

    public function suspend(Request $request, ProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('suspend', $providerUser);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->accounts->suspend($providerUser, $validated['reason'] ?? null, $request->user(), $request);

        return back()->with('flash', ['message' => __('provider-users.suspended'), 'type' => 'warning']);
    }

    public function activate(Request $request, ProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('activate', $providerUser);

        $this->accounts->activate($providerUser, $request->user(), $request);

        return back()->with('flash', ['message' => __('provider-users.activated'), 'type' => 'success']);
    }

    public function resetPassword(Request $request, ProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('resetPassword', $providerUser);

        $validated = $request->validate([
            // Blank: a generated one-time password. Typed: the central policy, incl. history.
            'password' => $request->filled('password')
                ? app(PasswordPolicy::class)->rules($providerUser, confirmed: false)
                : ['nullable'],
        ]);

        $temporary = $this->accounts->resetPassword($providerUser, $validated['password'] ?? null, $request->user());

        return back()->with('flash', array_filter([
            'message' => __('provider-users.password_reset').($temporary ? ' '.__('password-policy.temporary_password_created') : ''),
            'type' => 'success',
            'temporary_password' => $temporary,
        ]));
    }

    public function destroy(Request $request, ProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('delete', $providerUser);

        $this->accounts->delete($providerUser, $request->user(), $request);

        return redirect()->route('provider-users.index')
            ->with('flash', ['message' => __('provider-users.deleted'), 'type' => 'success']);
    }

    public function restore(Request $request, ProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('restore', $providerUser);
        abort_unless($providerUser->trashed(), 404);

        $this->accounts->restore($providerUser, $request->user(), $request);

        return redirect()->route('provider-users.show', $providerUser)
            ->with('flash', ['message' => __('provider-users.restored'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(ProviderUser $account): array
    {
        $provider = $account->provider;

        return [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'username' => $account->username,
            'phone_number' => $account->phone_number,
            'provider_role' => $account->provider_role,
            'status' => $account->status,
            'portal_enabled' => (bool) $account->portal_enabled,
            'must_change_password' => (bool) $account->must_change_password,
            'last_login_at' => $account->last_login_at?->toIso8601String(),
            'deleted_at' => $account->deleted_at?->toIso8601String(),
            'provider' => $provider ? [
                'id' => $provider->id,
                'code' => $provider->provider_code,
                'name_en' => $provider->name_en,
                'name_am' => $provider->name_am,
                'status' => $provider->status,
                'deleted' => $provider->trashed(),
                'type' => $provider->providerType ? [
                    'code' => $provider->providerType->code,
                    'name_en' => $provider->providerType->name_en,
                    'name_am' => $provider->providerType->name_am,
                ] : null,
            ] : null,
        ];
    }

    /** @return array<string, bool> */
    private function abilities(User $user, ProviderUser $account): array
    {
        $deleted = $account->trashed();

        return [
            'view' => $user->can('view', $account),
            'update' => ! $deleted && $user->can('update', $account),
            'resetPassword' => ! $deleted && $user->can('resetPassword', $account),
            'suspend' => ! $deleted && $account->status !== 'suspended' && $user->can('suspend', $account),
            'activate' => ! $deleted && $account->status !== 'active' && $user->can('activate', $account),
            'delete' => ! $deleted && $user->can('delete', $account),
            'restore' => $deleted && $user->can('restore', $account),
        ];
    }

    /**
     * Providers an account can be created for, with what each offers an
     * operator. A deleted provider is listed only for the account being edited.
     *
     * @return list<array<string, mixed>>
     */
    private function providerOptions(?string $includeId = null): array
    {
        return Provider::query()
            ->when($includeId, fn (Builder $query, string $id) => $query->withTrashed()->where(fn (Builder $q) => $q->whereNull('deleted_at')->orWhere('id', $id)))
            ->with(['providerType:id,code,name_en,name_am', 'services.serviceType:id,code'])
            ->orderBy('name_en')
            ->get()
            ->map(function (Provider $provider): array {
                $services = ProviderUserPermissionCatalog::activeServiceCodes($provider);

                return [
                    'id' => $provider->id,
                    'code' => $provider->provider_code,
                    'name_en' => $provider->name_en,
                    'name_am' => $provider->name_am,
                    'status' => $provider->status,
                    'type' => $provider->providerType?->code,
                    'services' => $services,
                    'permissions' => ProviderUserPermissionCatalog::forServices($services),
                ];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function serviceOptions(): array
    {
        return ServiceType::query()
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['code', 'name_en', 'name_am'])
            ->map(fn (ServiceType $type): array => ['code' => $type->code, 'name_en' => $type->name_en, 'name_am' => $type->name_am])
            ->all();
    }

    private function uuidOrNull(string $value): ?string
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }
}
