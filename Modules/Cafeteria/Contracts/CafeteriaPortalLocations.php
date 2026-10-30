<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts;

use Illuminate\Http\Request;
use Modules\Cafeteria\Contracts\Data\CafeteriaPortalSelection;

/**
 * The cafeteria locations a signed-in provider user may work at in the
 * provider portal, and the one selected (switcher choice remembered in the
 * session). Empty when the provider runs no cafeteria.
 */
interface CafeteriaPortalLocations
{
    public function selection(Request $request): CafeteriaPortalSelection;
}
