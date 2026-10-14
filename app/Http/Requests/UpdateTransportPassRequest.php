<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateTransportPassRequest extends StoreTransportPassRequest
{
    public function authorize(): bool
    {
        return auth('web')->user()?->can('transport-passes.update') ?? false;
    }
}
