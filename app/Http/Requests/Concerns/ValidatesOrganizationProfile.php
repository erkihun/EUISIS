<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\FieldWork\FieldWorkSettings;

/**
 * An organization's short name, official contact and location, shared by the
 * create and edit requests. A form that leaves a field out keeps its value;
 * an emptied field clears it. The check-in radius follows the field work
 * limit, since it pre-fills the geofence of field work sent here.
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

        $this->merge($values);
    }

    /** @return array<string, list<mixed>> */
    protected function organizationProfileRules(): array
    {
        $maxRadius = app(FieldWorkSettings::class)->maxGeofenceRadius();

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
