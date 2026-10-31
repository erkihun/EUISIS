<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

class UpdateTransportVehicleRequest extends StoreTransportVehicleRequest
{
    public function authorize(): bool
    {
        return $this->authorizeTransport('transport-vehicles.update', 'provider.transport.vehicles.manage');
    }
}
