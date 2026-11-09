<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

class UpdateTransportDriverRequest extends StoreTransportDriverRequest
{
    public function authorize(): bool
    {
        return $this->authorizeTransport('transport-drivers.update', 'provider.transport.drivers.manage');
    }
}
