<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ServiceProvider;
use App\Models\ServiceProviderUser;
use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ServiceProviderUserController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', ServiceProvider::class);

        $query = ServiceProviderUser::query()
            ->with([
                'serviceType:id,code,name_en',
                'serviceProvider:id,name,code,status',
            ])
            ->when($request->string('search')->isNotEmpty(), function ($q) use ($request): void {
                $search = $request->string('search')->toString();

                $q->where(function ($nested) use ($search): void {
                    $nested
                        ->where('name', ci_like_operator(), "%{$search}%")
                        ->orWhere('email', ci_like_operator(), "%{$search}%")
                        ->orWhere('username', ci_like_operator(), "%{$search}%")
                        ->orWhereHas('serviceType', function ($serviceTypeQuery) use ($search): void {
                            $serviceTypeQuery
                                ->where('name_en', ci_like_operator(), "%{$search}%")
                                ->orWhere('code', ci_like_operator(), "%{$search}%");
                        });
                });
            })
            ->when($request->string('status')->isNotEmpty(), function ($q) use ($request): void {
                $q->where('status', $request->string('status')->toString());
            })
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $user = $request->user();

        return Inertia::render('ServiceProviderUsers/AllIndex', [
            'providerUsers' => $query->through(fn (ServiceProviderUser $pu) => [
                'id' => $pu->id,
                'name' => $pu->name ?? 'Provider User',
                'email' => $pu->email,
                'username' => $pu->username,
                'phone_number' => $pu->phone_number,
                'status' => $pu->status ?? 'active',
                'portal_enabled' => (bool) $pu->portal_enabled,
                'must_change_password' => (bool) $pu->must_change_password,
                'service_type' => $pu->serviceType ? [
                    'id' => $pu->serviceType->id,
                    'code' => $pu->serviceType->code,
                    'name_en' => $pu->serviceType->name_en,
                ] : null,
                'provider' => $pu->serviceProvider ? [
                    'id' => $pu->serviceProvider->id,
                    'code' => $pu->serviceProvider->code,
                    'name' => $pu->serviceProvider->name,
                    'status' => $pu->serviceProvider->status,
                ] : null,
                'can' => [
                    'view' => $user?->can('viewAny', ServiceProvider::class) ?? false,
                    'edit' => $user?->can('create', ServiceProvider::class) ?? false,
                    'delete' => $user?->can('create', ServiceProvider::class) ?? false,
                    'suspend' => $user?->can('create', ServiceProvider::class) ?? false,
                    'resetPassword' => $user?->can('create', ServiceProvider::class) ?? false,
                ],
            ]),
            'meta' => [
                'current_page' => $query->currentPage(),
                'last_page' => $query->lastPage(),
                'total' => $query->total(),
            ],
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function show(ServiceProviderUser $providerUser): Response
    {
        $this->authorize('viewAny', ServiceProvider::class);

        $providerUser->load(['serviceType:id,code,name_en', 'serviceProvider:id,name,code,status']);

        return Inertia::render('ServiceProviderUsers/Show', [
            'providerUser' => [
                'id' => $providerUser->id,
                'name' => $providerUser->name,
                'email' => $providerUser->email,
                'username' => $providerUser->username,
                'phone_number' => $providerUser->phone_number,
                'status' => $providerUser->status,
                'portal_enabled' => (bool) $providerUser->portal_enabled,
                'must_change_password' => (bool) $providerUser->must_change_password,
                'last_login_at' => $providerUser->last_login_at?->toDateTimeString(),
                'suspended_at' => $providerUser->suspended_at?->toDateTimeString(),
                'suspension_reason' => $providerUser->suspension_reason,
                'created_at' => $providerUser->created_at?->toDateTimeString(),
                'service_type' => $providerUser->serviceType ? [
                    'id' => $providerUser->serviceType->id,
                    'code' => $providerUser->serviceType->code,
                    'name_en' => $providerUser->serviceType->name_en,
                ] : null,
                'provider' => $providerUser->serviceProvider ? [
                    'id' => $providerUser->serviceProvider->id,
                    'code' => $providerUser->serviceProvider->code,
                    'name' => $providerUser->serviceProvider->name,
                    'status' => $providerUser->serviceProvider->status,
                ] : null,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ServiceProvider::class);

        return Inertia::render('ServiceProviderUsers/Create', [
            'serviceTypes' => $this->serviceTypeOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ServiceProvider::class);

        $validated = $request->validate([
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:service_provider_users,email'],
            'username' => ['nullable', 'string', 'max:100', 'unique:service_provider_users,username', 'alpha_dash'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'password' => ['required', Password::min(8)],
            'status' => ['required', 'string', 'in:active,inactive,suspended'],
            'portal_enabled' => ['boolean'],
            'must_change_password' => ['boolean'],
        ]);

        if (empty($validated['email']) && empty($validated['username'])) {
            return back()
                ->withErrors(['email' => __('cafeteria.providerUserNeedsEmailOrUsername')])
                ->withInput();
        }

        ServiceProviderUser::query()->create([
            ...$validated,
            'password' => Hash::make($validated['password']),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('provider-users.index')
            ->with('flash', ['message' => __('providers.provider_user_saved'), 'type' => 'success']);
    }

    public function edit(ServiceProviderUser $providerUser): Response
    {
        $this->authorize('create', ServiceProvider::class);

        return Inertia::render('ServiceProviderUsers/Edit', [
            'providerUser' => [
                'id' => $providerUser->id,
                'name' => $providerUser->name,
                'email' => $providerUser->email,
                'username' => $providerUser->username,
                'phone_number' => $providerUser->phone_number,
                'status' => $providerUser->status,
                'portal_enabled' => (bool) $providerUser->portal_enabled,
                'must_change_password' => (bool) $providerUser->must_change_password,
                'service_type_id' => $providerUser->service_type_id,
            ],
            'serviceTypes' => $this->serviceTypeOptions(),
        ]);
    }

    public function update(Request $request, ServiceProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('create', ServiceProvider::class);

        $validated = $request->validate([
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', "unique:service_provider_users,email,{$providerUser->id}"],
            'username' => ['nullable', 'string', 'max:100', "unique:service_provider_users,username,{$providerUser->id}", 'alpha_dash'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'status' => ['required', 'string', 'in:active,inactive,suspended'],
            'portal_enabled' => ['boolean'],
            'must_change_password' => ['boolean'],
        ]);

        if (empty($validated['email']) && empty($validated['username'])) {
            return back()
                ->withErrors(['email' => __('cafeteria.providerUserNeedsEmailOrUsername')])
                ->withInput();
        }

        $providerUser->update([
            ...$validated,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('provider-users.show', $providerUser)
            ->with('flash', ['message' => __('providers.provider_user_saved'), 'type' => 'success']);
    }

    public function suspend(Request $request, ServiceProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('create', ServiceProvider::class);

        $validated = $request->validate([
            'suspension_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $providerUser->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_by' => $request->user()?->id,
            'suspension_reason' => $validated['suspension_reason'] ?? null,
        ]);

        return back()->with('flash', ['message' => __('cafeteria.providerUserSuspended'), 'type' => 'warning']);
    }

    public function activate(ServiceProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('create', ServiceProvider::class);

        $providerUser->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ]);

        return back()->with('flash', ['message' => __('cafeteria.providerUserActivated'), 'type' => 'success']);
    }

    public function resetPassword(Request $request, ServiceProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('create', ServiceProvider::class);

        $validated = $request->validate([
            'password' => ['required', Password::min(8)],
        ]);

        $providerUser->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => true,
            'updated_by' => $request->user()?->id,
        ]);

        return back()->with('flash', ['message' => __('cafeteria.providerUserPasswordReset'), 'type' => 'success']);
    }

    public function destroy(ServiceProviderUser $providerUser): RedirectResponse
    {
        $this->authorize('create', ServiceProvider::class);

        $providerUser->delete();

        return redirect()
            ->route('provider-users.index')
            ->with('flash', ['message' => __('cafeteria.providerUserDeleted'), 'type' => 'success']);
    }

    /** @return list<array<string, string>> */
    private function serviceTypeOptions(): array
    {
        return ServiceType::query()
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en'])
            ->map(fn (ServiceType $st) => [
                'id' => $st->id,
                'code' => $st->code,
                'name_en' => $st->name_en,
            ])
            ->values()
            ->all();
    }
}
