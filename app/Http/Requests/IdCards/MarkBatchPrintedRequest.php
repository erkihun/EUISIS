<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Models\CardPrintBatch;
use Illuminate\Foundation\Http\FormRequest;

class MarkBatchPrintedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('batch');

        return $batch instanceof CardPrintBatch
            ? $this->user()?->can('markPrinted', $batch) ?? false
            : ($this->user()?->can('id-cards.print') || $this->user()?->can('cards.manage')) ?? false;
    }

    public function rules(): array
    {
        return [
            'printer_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
