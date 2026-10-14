<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateTransportRouteRequest extends StoreTransportRouteRequest
{
    public function authorize(): bool
    {
        return $this->authorizeTransport('transport-routes.update', 'provider.transport.routes.manage');
    }
}
