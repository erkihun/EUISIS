<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Models\Employee;
use App\Models\IdCard;
use App\Models\IdCardPrintSnapshot;

/** The physical card's confirmed field list, not today's default template, owns impact. */
class IdCardFieldImpactService
{
    public const ROW_FIELDS = ['name' => 'name', 'sex' => 'gender', 'dob' => 'date_of_birth', 'nationality' => 'nationality', 'employment' => 'employment_type', 'phone' => 'phone', 'idNumber' => 'employee_number', 'email' => 'email', 'address' => 'address', 'emergency_contact_name' => 'emergency_contact_name', 'emergency_contact_phone' => 'emergency_contact_phone'];

    public function latest(IdCard $card): ?IdCardPrintSnapshot
    {
        return IdCardPrintSnapshot::query()->where('id_card_id', $card->id)->whereNotNull('printed_at')->orderByDesc('print_sequence')->first();
    }

    public function printedFields(IdCard $card): array
    {
        return $this->latest($card)?->rendered_fields ?? [];
    }

    public function renderedValues(IdCardRenderData $data): array
    {
        $values = [];
        if ($data->orientation === 'portrait') {
            $values = ['name' => [$data->fullNameAm, $data->fullNameEn], 'organization' => [$data->organizationNameAm, $data->organizationNameEn], 'position' => [$data->positionTitleAm, $data->positionTitleEn], 'employee_number' => $data->employeeNumber];
        } else {
            foreach ($data->bilingualFields as $row) {
                if (isset(self::ROW_FIELDS[$row[4]])) {
                    $values[self::ROW_FIELDS[$row[4]]] = [$row[1], $row[3]];
                }
            }
            // These rows really are rendered by the landscape SVG back.
            if ($data->emergencyContactFields !== []) {
                if (filled($data->emergencyContactName)) {
                    $values['emergency_contact_name'] = $data->emergencyContactName;
                }
                if (filled($data->emergencyContactPhone)) {
                    $values['emergency_contact_phone'] = $data->emergencyContactPhone;
                }
            }
        }
        if ($data->layout->showPhoto || ($data->orientation === 'landscape' && $data->backPhoto?->show)) {
            // Digest records the exact image without copying private image bytes into JSON.
            $values['photo'] = $data->photoDataUri ? hash('sha256', $data->photoDataUri) : null;
        }

        return $values;
    }

    public function currentValues(Employee $employee, array $fields): array
    {
        $employee->loadMissing('currentAssignment.organization', 'currentAssignment.position');
        $values = [];
        foreach ($fields as $field) {
            $value = match ($field) {
                'name' => [$employee->metadata['name_am'] ?? ($employee->full_name ?: $employee->name_en), $employee->name_en ?: ($employee->metadata['name_en'] ?? $employee->full_name)],
                'photo' => ($uri = app(IdCardAssetResolver::class)->resolvePhotoPath($employee->photo_path)) ? hash('sha256', $uri) : null,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'employment_type' => $employee->employment_type?->value,
                'organization' => [$employee->currentAssignment?->organization?->name_am, $employee->currentAssignment?->organization?->name_en],
                'position' => [$employee->currentAssignment?->position?->title_am, $employee->currentAssignment?->position?->title_en],
                default => in_array($field, array_values(self::ROW_FIELDS), true) ? $employee->getAttribute($field) : null,
            };
            $values[$field] = $value;
        }

        return $values;
    }
}
