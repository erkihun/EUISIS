<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateTransportRouteRequest extends StoreTransportRouteRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('transport-routes.update') ?? false) || (auth('provider')->user()?->canUseServicePermission('provider.transport.routes.manage') ?? false);
    }
}
