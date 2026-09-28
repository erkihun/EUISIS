<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CafeteriaTransaction;
use Illuminate\Foundation\Http\FormRequest;

class ReverseCafeteriaTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route declares {cafeteriaTransaction}; looking only for other names
        // refused every reversal with 403, whoever asked.
        $transaction = $this->route('cafeteriaTransaction') ?? $this->route('cafeteria_transaction') ?? $this->route('transaction');

        return $transaction instanceof CafeteriaTransaction
            ? ($this->user()?->can('reverse', $transaction) ?? false)
            : false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
