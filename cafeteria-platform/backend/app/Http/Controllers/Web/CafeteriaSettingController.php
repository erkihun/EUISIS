<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\UpdateCafeteriaSettingsAction;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCafeteriaSettingsRequest;
use App\Http\Resources\CafeteriaDayRuleResource;
use App\Http\Resources\CafeteriaSubsidyRuleResource;
use App\Http\Resources\PublicHolidayResource;
use App\Models\CafeteriaDayRule;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderAssignment;
use App\Models\CafeteriaProviderBranch;
use App\Models\CafeteriaSetting;
use App\Models\CafeteriaSubsidyRule;
use App\Models\Organization;
use App\Models\PublicHoliday;
use App\Models\ServiceProviderUser;
use App\Models\ServiceType;
use App\Services\Cafeteria\CafeteriaSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaSettingController extends Controller
{
    public function __construct(private readonly CafeteriaSettingsService $settingsService) {}

    public function index(): Response
    {
        $this->authorize('view', CafeteriaSetting::class);

        $validTabs = ['general', 'subsidy', 'days', 'scan', 'day-rules', 'holidays', 'subsidy-rules', 'reports', 'provider-users'];
        $tab = in_array(request()->query('tab'), $validTabs, true)
            ? request()->query('tab')
            : 'general';

        $user = request()->user();

        $dayRules = CafeteriaDayRule::query()
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->get();

        $year = request()->integer('year') ?: now()->year;
        $holidays = PublicHoliday::query()
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date')
            ->get();

        $subsidyRules = CafeteriaSubsidyRule::query()
            ->withoutTrashed()
            ->orderBy('effective_from', 'desc')
            ->get();

        return Inertia::render('Cafeteria/Settings/Index', [
            'cafeteriaSettings' => $this->settingsService->effectiveForForm(),
            'editableSettingKeys' => $this->settingsService->editableKeys(),
            'readOnlySettingReasons' => CafeteriaSettingsService::READ_ONLY,
            'activeTab' => $tab,
            'dayRules' => CafeteriaDayRuleResource::collection($dayRules),
            'holidays' => PublicHolidayResource::collection($holidays),
            'holidaysYear' => $year,
            'subsidyRules' => CafeteriaSubsidyRuleResource::collection($subsidyRules),
            'providerUsers' => $user?->can('cafeteria_settings.update') ? $this->providerUsers() : [],
            'providerOptions' => $user?->can('cafeteria_settings.update') ? $this->providerOptions() : [],
            'userOptions' => $user?->can('cafeteria_settings.update') ? $this->userOptions() : [],
            'organizationOptions' => $user?->can('cafeteria_settings.update') ? $this->organizationOptions() : [],
            'branchOptions' => $user?->can('cafeteria_settings.update') ? $this->branchOptions() : [],
            'can' => [
                'update' => $user?->can('cafeteria_settings.update') ?? false,
                'updateDayRules' => $user?->can('cafeteria_day_rules.update') ?? false,
                'createHoliday' => $user?->can('cafeteria_holidays.create') ?? false,
                'createSubsidyRule' => $user?->can('cafeteria_subsidy_rules.create') ?? false,
                'manageProviderUsers' => $user?->can('cafeteria_settings.update') ?? false,
            ],
        ]);
    }

    public function update(UpdateCafeteriaSettingsRequest $request, UpdateCafeteriaSettingsAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria.settingsUpdated'), 'type' => 'success']);
    }

    public function storeProviderUser(Request $request): RedirectResponse
    {
        $this->authorize('update', CafeteriaSetting::class);

        $validated = $request->validate([
            'service_provider_user_id' => ['required', 'uuid', 'exists:service_provider_users,id'],
            'cafeteria_provider_id' => ['required', 'uuid', 'exists:cafeteria_providers,id'],
            'cafeteria_provider_branch_id' => ['nullable', 'uuid', 'exists:cafeteria_provider_branches,id'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'provider_role' => ['nullable', 'string', 'max:100'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $isCafeteriaProviderUser = ServiceProviderUser::query()
            ->whereKey($validated['service_provider_user_id'])
            ->whereHas('serviceType', fn ($query) => $query->where('code', 'cafeteria'))
            ->exists();

        if (! $isCafeteriaProviderUser) {
            return back()
                ->withErrors(['service_provider_user_id' => __('cafeteria.providerAccessDenied')])
                ->withInput();
        }

        $this->validateAssignmentRelations($validated);

        DB::transaction(function () use ($request, $validated): void {
            CafeteriaProviderAssignment::query()->updateOrCreate(
                [
                    'service_provider_user_id' => $validated['service_provider_user_id'],
                    'cafeteria_provider_id' => $validated['cafeteria_provider_id'],
                    'cafeteria_provider_branch_id' => $validated['cafeteria_provider_branch_id'] ?? null,
                    'organization_id' => $validated['organization_id'] ?? null,
                ],
                [
                    'cafeteria_provider_branch_id' => $validated['cafeteria_provider_branch_id'] ?? null,
                    'organization_id' => $validated['organization_id'] ?? null,
                    'role' => $validated['provider_role'] ?? 'operator',
                    'provider_role' => $validated['provider_role'] ?? 'operator',
                    'is_active' => true,
                    'assigned_by' => $request->user()?->id,
                    'effective_from' => $validated['effective_from'] ?? null,
                    'effective_to' => $validated['effective_to'] ?? null,
                ],
            );
        });

        return back()->with('flash', ['message' => __('cafeteria.providerUserSaved'), 'type' => 'success']);
    }

    public function updateProviderUser(Request $request, CafeteriaProviderAssignment $providerUser): RedirectResponse
    {
        $this->authorize('update', CafeteriaSetting::class);

        $validated = $request->validate([
            'service_provider_user_id' => ['nullable', 'uuid', 'exists:service_provider_users,id'],
            'cafeteria_provider_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],
            'cafeteria_provider_branch_id' => ['nullable', 'uuid', 'exists:cafeteria_provider_branches,id'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'provider_role' => ['nullable', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $values = [
            'service_provider_user_id' => $validated['service_provider_user_id'] ?? $providerUser->service_provider_user_id,
            'cafeteria_provider_id' => $validated['cafeteria_provider_id'] ?? $providerUser->cafeteria_provider_id,
            'cafeteria_provider_branch_id' => array_key_exists('cafeteria_provider_branch_id', $validated) ? $validated['cafeteria_provider_branch_id'] : $providerUser->cafeteria_provider_branch_id,
            'organization_id' => array_key_exists('organization_id', $validated) ? $validated['organization_id'] : $providerUser->organization_id,
            'role' => $validated['provider_role'] ?? $providerUser->role,
            'provider_role' => $validated['provider_role'] ?? $providerUser->provider_role,
            'is_active' => $validated['is_active'],
            'effective_from' => array_key_exists('effective_from', $validated) ? $validated['effective_from'] : $providerUser->effective_from?->toDateString(),
            'effective_to' => array_key_exists('effective_to', $validated) ? $validated['effective_to'] : $providerUser->effective_to?->toDateString(),
        ];
        $this->validateAssignmentRelations($values);
        $providerUser->update($values);

        return back()->with('flash', ['message' => __('cafeteria.providerUserUpdated'), 'type' => 'success']);
    }

    private function validateAssignmentRelations(array $values): void
    {
        if (! empty($values['service_provider_user_id']) && ! ServiceProviderUser::query()
            ->whereKey($values['service_provider_user_id'])
            ->whereHas('serviceType', fn ($query) => $query->where('code', 'cafeteria'))->exists()) {
            throw ValidationException::withMessages(['service_provider_user_id' => __('cafeteria.providerAccessDenied')]);
        }
        if (! empty($values['cafeteria_provider_branch_id']) && ! CafeteriaProviderBranch::query()
            ->whereKey($values['cafeteria_provider_branch_id'])
            ->where('cafeteria_provider_id', $values['cafeteria_provider_id'])->exists()) {
            throw ValidationException::withMessages(['cafeteria_provider_branch_id' => __('cafeteria.providerAccessDenied')]);
        }
        if (! empty($values['effective_from']) && ! empty($values['effective_to']) && $values['effective_to'] < $values['effective_from']) {
            throw ValidationException::withMessages(['effective_to' => __('validation.after_or_equal', ['attribute' => 'effective_to', 'date' => 'effective_from'])]);
        }
    }

    public function destroyProviderUser(CafeteriaProviderAssignment $providerUser): RedirectResponse
    {
        $this->authorize('update', CafeteriaSetting::class);

        $providerUser->delete();

        return back()->with('flash', ['message' => __('cafeteria.providerUserDeleted'), 'type' => 'success']);
    }

    /** @return list<array<string, mixed>> */
    private function providerUsers(): array
    {
        return CafeteriaProviderAssignment::query()
            ->with([
                'provider:id,code,name_en,name_am',
                'organization:id,name_en,name_am,code',
                'branch:id,code,name_en,name_am',
                'serviceProviderUser:id,name,email,username,status,portal_enabled',
                'user:id,name,email,status,user_type,provider_portal_enabled',
            ])
            ->latest()
            ->get()
            ->map(fn (CafeteriaProviderAssignment $assignment): array => [
                'id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'service_provider_user_id' => $assignment->service_provider_user_id,
                'cafeteria_provider_id' => $assignment->cafeteria_provider_id,
                'cafeteria_provider_branch_id' => $assignment->cafeteria_provider_branch_id,
                'organization_id' => $assignment->organization_id,
                'provider_role' => $assignment->provider_role ?? $assignment->role,
                'is_active' => (bool) $assignment->is_active,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'user' => [
                    'name' => $assignment->serviceProviderUser?->name ?? $assignment->user?->name,
                    'email' => $assignment->serviceProviderUser?->email ?? $assignment->user?->email,
                    'username' => $assignment->serviceProviderUser?->username,
                    'status' => $assignment->serviceProviderUser?->status ?? $assignment->user?->status,
                    'user_type' => $assignment->user?->user_type,
                    'provider_portal_enabled' => (bool) ($assignment->serviceProviderUser?->portal_enabled ?? $assignment->user?->provider_portal_enabled),
                ],
                'provider' => [
                    'code' => $assignment->provider?->code,
                    'name_en' => $assignment->provider?->name_en,
                    'name_am' => $assignment->provider?->name_am,
                ],
                'organization' => $assignment->organization ? [
                    'name_en' => $assignment->organization->name_en,
                    'name_am' => $assignment->organization->name_am,
                    'code' => $assignment->organization->code,
                ] : null,
                'branch' => $assignment->branch ? [
                    'id' => $assignment->branch->id,
                    'code' => $assignment->branch->code,
                    'name_en' => $assignment->branch->name_en,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function organizationOptions(): array
    {
        return Organization::query()
            ->where('status', OrganizationStatus::Active)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am', 'code'])
            ->map(fn (Organization $org): array => [
                'id' => $org->id,
                'name_en' => $org->name_en,
                'name_am' => $org->name_am,
                'code' => $org->code,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function branchOptions(): array
    {
        return CafeteriaProviderBranch::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with('provider:id,name_en,code')
            ->orderBy('name_en')
            ->get()
            ->map(fn (CafeteriaProviderBranch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name_en' => $branch->name_en,
                'name_am' => $branch->name_am,
                'cafeteria_provider_id' => $branch->cafeteria_provider_id,
                'organization_id' => $branch->organization_id,
                'provider_name_en' => $branch->provider?->name_en,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function providerOptions(): array
    {
        return CafeteriaProvider::query()
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_am', 'organization_id'])
            ->map(fn (CafeteriaProvider $provider): array => [
                'id' => $provider->id,
                'code' => $provider->code,
                'name_en' => $provider->name_en,
                'name_am' => $provider->name_am,
                'organization_id' => $provider->organization_id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function userOptions(): array
    {
        $cafeteriaServiceTypeId = ServiceType::query()
            ->where('code', 'cafeteria')
            ->value('id');

        if (! $cafeteriaServiceTypeId) {
            return [];
        }

        return ServiceProviderUser::query()
            ->where('service_type_id', $cafeteriaServiceTypeId)
            ->where('status', 'active')
            ->where('portal_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'username'])
            ->map(fn (ServiceProviderUser $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
            ])
            ->values()
            ->all();
    }
}
