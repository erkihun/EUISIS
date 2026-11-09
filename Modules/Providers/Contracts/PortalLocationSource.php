<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts;

use Illuminate\Http\Request;
use Modules\Providers\Contracts\Data\PortalLocationSelection;

/**
 * The service locations a signed-in provider user can switch between in the
 * provider portal, and the one selected. A service module that runs
 * locations (today the cafeteria) implements it; without one the portal
 * shows no switcher.
 */
interface PortalLocationSource
{
    public function selection(Request $request): PortalLocationSelection;
}
