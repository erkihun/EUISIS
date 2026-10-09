<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateTransportDriverRequest extends StoreTransportDriverRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('transport-drivers.update') ?? false) || (auth('provider')->user()?->canUseServicePermission('provider.transport.drivers.manage') ?? false);
    }
}
