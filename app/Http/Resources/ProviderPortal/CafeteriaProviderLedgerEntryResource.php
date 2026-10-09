<?php

declare(strict_types=1);

namespace App\Http\Resources\ProviderPortal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaProviderLedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date?->toDateString(),
            'entry_type' => $this->entry_type,
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'balance_after' => (float) $this->balance_after,
            'description' => $this->description,
            'transaction' => $this->whenLoaded('transaction', fn () => [
                'id' => $this->transaction?->id,
                'transaction_number' => $this->transaction?->transaction_number,
            ]),
        ];
    }
}
