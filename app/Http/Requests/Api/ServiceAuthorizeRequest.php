<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Validator;

/**
 * The path names the service. A body `service_type` may be omitted; when it
 * is given it must be the same service, never silently overridden by the path.
 */
class ServiceAuthorizeRequest extends VerifyCardApiRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('service_type')) {
            $this->merge(['service_type' => $this->route('serviceType')]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('service_type') !== $this->route('serviceType')) {
                $validator->errors()->add('service_type', 'The service type must match the one in the path.');
            }
        }];
    }
}
