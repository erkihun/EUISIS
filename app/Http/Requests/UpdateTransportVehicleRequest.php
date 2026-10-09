<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateTransportVehicleRequest extends StoreTransportVehicleRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('transport-vehicles.update') ?? false) || (auth('provider')->user()?->canUseServicePermission('provider.transport.vehicles.manage') ?? false);
    }
}
