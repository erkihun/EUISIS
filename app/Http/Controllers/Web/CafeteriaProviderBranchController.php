<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderBranch;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaProviderBranchController extends Controller
{
    public function create(Request $request, CafeteriaProvider $cafeteriaProvider): Response
    {
        $this->authorize('update', $cafeteriaProvider);

        return Inertia::render('Cafeteria/Providers/Branches/Create', [
            'provider' => [
                'id' => $cafeteriaProvider->id,
                'code' => $cafeteriaProvider->code,
                'name_en' => $cafeteriaProvider->name_en,
            ],
            'organizations' => $this->organizationOptions(),
        ]);
    }

    public function store(Request $request, CafeteriaProvider $cafeteriaProvider): RedirectResponse
    {
        $this->authorize('update', $cafeteriaProvider);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:cafeteria_provider_branches,code'],
            'name_en' => ['required', 'string', 'max:200'],
            'name_am' => ['nullable', 'string', 'max:200'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ]);

        $cafeteriaProvider->branches()->create([
            ...$validated,
            'created_by' => $request->user()?->id,
        ]);

        return to_route('cafeteria.providers.show', $cafeteriaProvider)
            ->with('flash', ['message' => __('cafeteria.branchCreated'), 'type' => 'success']);
    }

    public function edit(Request $request, CafeteriaProvider $cafeteriaProvider, CafeteriaProviderBranch $branch): Response
    {
        $this->authorize('update', $cafeteriaProvider);

        return Inertia::render('Cafeteria/Providers/Branches/Edit', [
            'provider' => [
                'id' => $cafeteriaProvider->id,
                'code' => $cafeteriaProvider->code,
                'name_en' => $cafeteriaProvider->name_en,
            ],
            'branch' => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name_en' => $branch->name_en,
                'name_am' => $branch->name_am,
                'organization_id' => $branch->organization_id,
                'location' => $branch->location,
                'contact_person' => $branch->contact_person,
                'phone_number' => $branch->phone_number,
                'is_active' => $branch->is_active,
            ],
            'organizations' => $this->organizationOptions(),
        ]);
    }

    public function update(Request $request, CafeteriaProvider $cafeteriaProvider, CafeteriaProviderBranch $branch): RedirectResponse
    {
        $this->authorize('update', $cafeteriaProvider);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:cafeteria_provider_branches,code,'.$branch->id],
            'name_en' => ['required', 'string', 'max:200'],
            'name_am' => ['nullable', 'string', 'max:200'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ]);

        $branch->update([...$validated, 'updated_by' => $request->user()?->id]);

        return to_route('cafeteria.providers.show', $cafeteriaProvider)
            ->with('flash', ['message' => __('cafeteria.branchUpdated'), 'type' => 'success']);
    }

    public function archive(Request $request, CafeteriaProvider $cafeteriaProvider, CafeteriaProviderBranch $branch): RedirectResponse
    {
        $this->authorize('update', $cafeteriaProvider);

        $branch->delete();

        return back()->with('flash', ['message' => __('cafeteria.branchArchived'), 'type' => 'success']);
    }

    /** @return array<int, array<string, string|null>> */
    private function organizationOptions(): array
    {
        return Organization::query()
            ->where('status', OrganizationStatus::Active)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am', 'code'])
            ->toArray();
    }
}
