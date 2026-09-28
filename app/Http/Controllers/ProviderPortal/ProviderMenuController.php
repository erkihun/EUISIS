<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Http\Resources\ProviderPortal\CafeteriaMenuResource;
use App\Models\CafeteriaMenu;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProviderMenuController extends Controller
{
    use FormatsProviderPortalData;

    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $menus = CafeteriaMenu::query()
            ->withCount('orders')
            ->where('cafeteria_provider_id', $provider->id)
            ->when($request->string('date')->toString(), fn ($query, string $date) => $query->whereDate('menu_date', $date))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('menu_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Cafeteria/Portal/Menus/Index', [
            ...$this->portalPayload($request, $context, $provider),
            'menus' => CafeteriaMenuResource::collection($menus)->resolve(),
            'meta' => [
                'current_page' => $menus->currentPage(),
                'last_page' => $menus->lastPage(),
                'total' => $menus->total(),
            ],
            'filters' => $request->only(['date', 'status']),
        ]);
    }

    public function create(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        return Inertia::render('Cafeteria/Portal/Menus/Form', [
            ...$this->portalPayload($request, $context, $provider),
            'menu' => null,
        ]);
    }

    public function store(Request $request, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $validated = $this->validatedMenu($request);

        DB::transaction(function () use ($provider, $request, $validated): void {
            $menu = CafeteriaMenu::query()->create([
                ...$validated,
                'cafeteria_provider_id' => $provider->id,
                'created_by' => $this->staffUserId($request),
                'updated_by' => $this->staffUserId($request),
                'status' => $validated['status'] ?? 'draft',
            ]);

            $this->syncItems($menu, $validated['items'] ?? []);
        });

        return redirect()->route('provider.portal.menus.index')->with('flash', [
            'message' => __('provider-portal.menu_saved'),
            'type' => 'success',
        ]);
    }

    public function edit(Request $request, CafeteriaMenu $menu, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $menu->cafeteria_provider_id !== $provider->id, 404);

        $menu->load('items');

        return Inertia::render('Cafeteria/Portal/Menus/Form', [
            ...$this->portalPayload($request, $context, $provider),
            'menu' => (new CafeteriaMenuResource($menu))->resolve(),
        ]);
    }

    public function update(Request $request, CafeteriaMenu $menu, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $menu->cafeteria_provider_id !== $provider->id, 404);

        $validated = $this->validatedMenu($request);

        DB::transaction(function () use ($menu, $request, $validated): void {
            $menu->update([
                ...$validated,
                'updated_by' => $this->staffUserId($request),
            ]);

            $this->syncItems($menu, $validated['items'] ?? []);
        });

        return redirect()->route('provider.portal.menus.index')->with('flash', [
            'message' => __('provider-portal.menu_saved'),
            'type' => 'success',
        ]);
    }

    public function show(Request $request, CafeteriaMenu $menu, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $menu->cafeteria_provider_id !== $provider->id, 404);

        $menu->load('items')->loadCount('orders');

        return Inertia::render('Cafeteria/Portal/Menus/Show', [
            ...$this->portalPayload($request, $context, $provider),
            'menu' => (new CafeteriaMenuResource($menu))->resolve(),
        ]);
    }

    public function publish(Request $request, CafeteriaMenu $menu, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $menu->cafeteria_provider_id !== $provider->id, 404);

        $menu->update(['status' => 'published', 'updated_by' => $this->staffUserId($request)]);

        return back()->with('flash', ['message' => __('provider-portal.menu_published'), 'type' => 'success']);
    }

    public function close(Request $request, CafeteriaMenu $menu, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $menu->cafeteria_provider_id !== $provider->id, 404);

        $menu->update(['status' => 'closed', 'updated_by' => $this->staffUserId($request)]);

        return back()->with('flash', ['message' => __('provider-portal.menu_closed'), 'type' => 'success']);
    }

    public function destroy(Request $request, CafeteriaMenu $menu, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $menu->cafeteria_provider_id !== $provider->id, 404);

        $menu->delete();

        return redirect()->route('provider.portal.menus.index')->with('flash', [
            'message' => __('provider-portal.menu_deleted'),
            'type' => 'success',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedMenu(Request $request): array
    {
        return $request->validate([
            'menu_date' => ['required', 'date'],
            'title_en' => ['required', 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'meal_type' => ['required', Rule::in(['breakfast', 'lunch', 'dinner', 'snack'])],
            'price' => ['required', 'numeric', 'min:0'],
            'subsidy_eligible' => ['boolean'],
            'max_orders' => ['nullable', 'integer', 'min:1'],
            'order_cutoff_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'closed', 'cancelled'])],
            'items' => ['array'],
            'items.*.name_en' => ['required_with:items', 'string', 'max:255'],
            'items.*.name_am' => ['nullable', 'string', 'max:255'],
            'items.*.item_type' => ['nullable', 'string', 'max:80'],
            'items.*.is_available' => ['boolean'],
        ]);
    }

    /** @param list<array<string, mixed>> $items */
    private function syncItems(CafeteriaMenu $menu, array $items): void
    {
        $menu->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $menu->items()->create([
                'name_en' => $item['name_en'],
                'name_am' => $item['name_am'] ?? null,
                'item_type' => $item['item_type'] ?? null,
                'is_available' => $item['is_available'] ?? true,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
