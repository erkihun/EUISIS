<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

class UpdateTransportPassRequest extends StoreTransportPassRequest
{
    public function authorize(): bool
    {
        return auth('web')->user()?->can('transport-passes.update') ?? false;
    }
}
