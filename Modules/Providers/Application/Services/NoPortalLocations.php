<?php

declare(strict_types=1);

namespace Modules\Providers\Application\Services;

use Illuminate\Http\Request;
use Modules\Providers\Contracts\Data\PortalLocationSelection;
use Modules\Providers\Contracts\PortalLocationSource;

/** The portal without a location-running service module: no switcher. */
final class NoPortalLocations implements PortalLocationSource
{
    public function selection(Request $request): PortalLocationSelection
    {
        return PortalLocationSelection::none();
    }
}
