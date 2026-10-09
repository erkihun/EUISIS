<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Models\IdCard;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;

class CreatePrintBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('id-cards.createPrintBatch')
            || $this->user()?->can('cards.manage')
            ? true
            : false;
    }

    public function rules(): array
    {
        return [
            'card_ids' => ['required', 'array', 'min:1'],
            'card_ids.*' => [
                'required', 'uuid', 'exists:id_cards,id',
                // Batching is a bulk cross-employee action — an Organizational
                // Admin (or any other org-scoped actor) must not be able to
                // sneak a card belonging to an out-of-scope employee into an
                // otherwise-legitimate batch by passing arbitrary card_ids.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $user = $this->user();
                    if ($user === null) {
                        return;
                    }

                    $card = IdCard::query()->with('employee.currentAssignment')->find($value);
                    if ($card === null || $card->employee === null) {
                        return; // the `exists` rule already reports the missing-card case
                    }

                    if (! app(OrganizationScopeService::class)->canAccessEmployee($user, $card->employee)) {
                        $fail(__('users.access_denied_outside_scope'));
                    }
                },
            ],
        ];
    }
}
