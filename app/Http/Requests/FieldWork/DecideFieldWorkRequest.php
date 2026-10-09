<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use App\Models\FieldWorkRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve, return or reject. Authorised against the record before
 * validation, so someone who is not the request's supervisor is refused
 * outright. Return and reject require a reason.
 */
class DecideFieldWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('fieldWorkRequest');

        return $request instanceof FieldWorkRequest && $this->user()->can($this->ability(), $request);
    }

    public function rules(): array
    {
        return [
            'reason' => $this->ability() === 'approve'
                ? ['nullable', 'string', 'max:2000']
                : ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function ability(): string
    {
        return match (true) {
            $this->routeIs('field-work.requests.return') => 'returnForCorrection',
            $this->routeIs('field-work.requests.reject') => 'reject',
            default => 'approve',
        };
    }
}
