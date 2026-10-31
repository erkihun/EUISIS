<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateReceptionReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.operate') ?? false;
    }

    public function rules(): array
    {
        return ['outcome' => ['nullable', 'string', 'max:2000']];
    }
}
