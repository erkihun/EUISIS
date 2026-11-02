<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Organizations\OrganizationDuplicateNameFinder;
use Illuminate\Validation\Validator;
use Modules\FieldWork\Contracts\FieldWorkGpsPolicy;

/**
 * An organization's short name, official contact and location, shared by the
 * create and edit requests. A form that leaves a field out keeps its value;
 * an emptied field clears it. The check-in radius follows the field work
 * limit, since it pre-fills the geofence of field work sent here. The two
 * names are stored with runs of spaces collapsed and may not repeat the name
 * of another live organization.
 */
trait ValidatesOrganizationProfile
{
    /** @var list<string> */
    private const PROFILE_FIELDS = [
        'short_name_en', 'short_name_am', 'official_email', 'website', 'po_box',
        'address_en', 'address_am', 'latitude', 'longitude', 'geofence_radius_m',
    ];

    protected function normalizeOrganizationProfile(): void
    {
        $values = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $values[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }

        // A bare domain is accepted and stored as a link.
        if (is_string($values['website'] ?? null) && ! preg_match('#^https?://#i', $values['website'])) {
            $values['website'] = 'https://'.$values['website'];
        }

        foreach (['name_en', 'name_am'] as $field) {
            if ($this->has($field)) {
                $values[$field] = OrganizationDuplicateNameFinder::normalize($this->input($field));
            }
        }

        $this->merge($values);
    }

    protected function rejectDuplicateOrganizationNames(Validator $validator, ?string $ignoreOrganizationId = null): void
    {
        $finder = app(OrganizationDuplicateNameFinder::class);

        foreach (['name_en', 'name_am'] as $field) {
            if ($validator->errors()->has($field)) {
                continue;
            }

            $existing = $finder->find($field, $this->input($field), $ignoreOrganizationId);

            if ($existing !== null) {
                $validator->errors()->add($field, __("organizations.duplicate_{$field}", ['code' => $existing->code]));
            }
        }
    }

    /** @return array<string, list<mixed>> */
    protected function organizationProfileRules(): array
    {
        $maxRadius = app(FieldWorkGpsPolicy::class)->maxGeofenceRadius();

        return [
            'short_name_en' => ['sometimes', 'nullable', 'string', 'max:50'],
            'short_name_am' => ['sometimes', 'nullable', 'string', 'max:50'],
            'official_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
            'po_box' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'address_am' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'geofence_radius_m' => ['sometimes', 'nullable', 'integer', 'min:25', "max:{$maxRadius}"],
        ];
    }
}
