<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfers;

use App\Enums\EstablishmentStatus;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTransferAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transfers.announcements.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'positions' => ['sometimes', 'array', 'min:1'],
            'positions.*.organization_id' => ['required_with:positions', 'uuid', 'exists:organizations,id'],
            'positions.*.position_id' => ['required_with:positions', 'uuid', 'exists:positions,id'],
            'positions.*.grade_level' => ['nullable', 'string', 'max:50'],
            'positions.*.salary_min' => ['nullable', 'numeric', 'min:0'],
            'positions.*.salary_max' => ['nullable', 'numeric', 'gte:positions.*.salary_min'],
            'positions.*.vacancy_count' => ['required_with:positions', 'integer', 'min:1', 'max:999'],
            'eligibility_rules' => ['nullable', 'array'],
            'eligibility_rules.*' => ['array:type,operator,value'],
            'eligibility_rules.*.type' => ['required', 'in:employment_status,current_grade,current_organization,current_position,minimum_service_months'],
            'eligibility_rules.*.operator' => ['required', 'in:equals,in,greater_than_or_equal'],
            'eligibility_rules.*.value' => ['required', 'string', 'max:200'],
            'required_documents' => ['nullable', 'array'],
            'required_documents.*' => ['string', 'max:100'],
            'opening_date' => ['required', 'date'],
            'closing_date' => ['required', 'date', 'after:opening_date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();
            if ($actor === null) {
                return;
            }

            $scope = app(OrganizationScopeService::class);
            $seen = [];
            foreach ((array) $this->input('positions', []) as $index => $row) {
                $positionId = $row['position_id'] ?? null;
                $organizationId = $row['organization_id'] ?? null;
                if (! is_string($positionId) || ! is_string($organizationId)) {
                    continue;
                }

                if (isset($seen[$positionId])) {
                    $validator->errors()->add("positions.$index.position_id", 'A position can be advertised only once per announcement.');

                    continue;
                }
                $seen[$positionId] = true;
                if (! $scope->canAccessOrganization($actor, $organizationId)) {
                    $validator->errors()->add("positions.$index.organization_id", 'The destination organization is outside your scope.');

                    continue;
                }
                $position = Position::query()->find($positionId);
                if ($position === null || $position->organization_id !== $organizationId || ! $position->isSelectable()) {
                    $validator->errors()->add("positions.$index.position_id", 'The selected position is not valid for the destination organization.');

                    continue;
                }
                $available = PositionEstablishment::query()
                    ->where('organization_id', $organizationId)
                    ->where('position_id', $positionId)
                    ->where('status', EstablishmentStatus::Approved->value)
                    ->get()
                    ->sum(fn (PositionEstablishment $establishment): int => $establishment->availableSlots());
                if ((int) ($row['vacancy_count'] ?? 0) > $available) {
                    $validator->errors()->add("positions.$index.vacancy_count", 'Advertised slots exceed currently available approved capacity.');
                }
            }
        });
    }
}
