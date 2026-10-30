<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Transport\Http\Requests\Concerns\TransportScanRules;

class TransportScanRequest extends FormRequest
{
    use TransportScanRules;

    public function authorize(): bool
    {
        return $this->user()?->can('transport-scan.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['provider_id' => ['required', 'uuid', 'exists:providers,id']]
            + $this->scanRules(manualAllowed: $this->user()?->can('transport-scan.manual') ?? false);
    }
}
