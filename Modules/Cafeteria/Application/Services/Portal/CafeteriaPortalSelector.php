<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Services\Portal;

use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Cafeteria\Contracts\CafeteriaPortalLocations;
use Modules\Cafeteria\Contracts\Data\CafeteriaPortalSelection;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;

/**
 * The cafeteria location the provider portal is working at (moved from
 * ProviderPortalContext). A provider may run several; the user may pick any
 * of their OWN provider's active ones (?provider_id=… from the switcher,
 * remembered in the session). A location of another provider can never be
 * selected.
 */
final class CafeteriaPortalSelector implements CafeteriaPortalLocations
{
    private const SELECTED_CAFETERIA = 'provider_portal.cafeteria_id';

    public function __construct(private readonly ProviderPortalContext $context) {}

    public function selectedProvider(Request $request): ?CafeteriaProvider
    {
        $cafeterias = $this->cafeterias($request);
        if ($cafeterias->isEmpty()) {
            return null;
        }

        $requested = $request->query('provider_id');
        if (is_string($requested) && $cafeterias->contains('id', $requested) && $request->hasSession()) {
            $request->session()->put(self::SELECTED_CAFETERIA, $requested);
        }

        $remembered = $request->hasSession() ? $request->session()->get(self::SELECTED_CAFETERIA) : null;

        return $cafeterias->firstWhere('id', $remembered) ?? $cafeterias->first();
    }

    /**
     * Active cafeteria locations of the signed-in user's provider, main first.
     *
     * @return Collection<int, CafeteriaProvider>
     */
    public function cafeterias(Request $request): Collection
    {
        $provider = $this->context->provider($request);

        if ($provider === null || ! $provider->hasService('cafeteria')) {
            return collect();
        }

        return $provider->cafeterias()
            ->where('is_active', true)
            ->orderByRaw("case when location_type = 'main' then 0 else 1 end")
            ->orderBy('name_en')
            ->get();
    }

    public function selection(Request $request): CafeteriaPortalSelection
    {
        return new CafeteriaPortalSelection(
            locations: $this->cafeterias($request)->map(fn (CafeteriaProvider $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'name_en' => $c->name_en,
                'name_am' => $c->name_am,
                'location' => $c->location,
            ])->values()->all(),
            selectedId: $this->selectedProvider($request)?->id,
        );
    }
}
