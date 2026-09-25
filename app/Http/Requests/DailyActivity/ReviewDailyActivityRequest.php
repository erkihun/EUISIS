<?php

declare(strict_types=1);

namespace App\Http\Requests\DailyActivity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve or return a daily activity. Authorisation is the policy's job
 * (checked in the controller against the record); this validates shape.
 * Returning for correction requires a comment.
 */
class ReviewDailyActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $returning = $this->routeIs('daily-activities.return');

        return [
            'comment' => $returning
                ? ['required', 'string', 'min:5', 'max:2000']
                : ['nullable', 'string', 'max:2000'],
            'item_notes' => ['nullable', 'array', 'max:30'],
            'item_notes.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> item id => note, only non-empty notes */
    public function itemNotes(): array
    {
        return array_filter(
            (array) $this->validated('item_notes', []),
            static fn ($note, $key): bool => is_string($key) && is_string($note) && trim($note) !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
