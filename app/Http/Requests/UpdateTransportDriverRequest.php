<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateTransportDriverRequest extends StoreTransportDriverRequest
{
    public function authorize(): bool
    {
        return $this->authorizeTransport('transport-drivers.update', 'provider.transport.drivers.manage');
    }
}
