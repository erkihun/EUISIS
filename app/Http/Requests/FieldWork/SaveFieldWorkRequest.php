<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use App\Enums\FieldWorkDestinationType;
use App\Models\OrganizationUnit;
use App\Services\FieldWork\FieldWorkSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / correct a field work request.
 *
 * Deliberately has no employee, assignment, status or supervisor field: the
 * requester and placement come from the signed-in user, the workflow from
 * FieldWorkService. Dates arrive as Gregorian local date + "HH:MM" from the
 * shared calendar pickers and become stored instants here.
 */
class SaveFieldWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ability to create or edit is checked in the controller against the record.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $destination = (string) $this->input('destination_type');
        $registered = $destination === FieldWorkDestinationType::RegisteredOrganization->value;
        $external = $destination === FieldWorkDestinationType::ExternalOrganization->value;
        $site = in_array($destination, [FieldWorkDestinationType::FieldSite->value, FieldWorkDestinationType::OtherLocation->value], true);
        $maxRadius = app(FieldWorkSettings::class)->maxGeofenceRadius();

        return [
            'action' => ['required', Rule::in(['draft', 'submit'])],
            'field_work_type_id' => ['required', 'uuid', Rule::exists('field_work_types', 'id')],
            'purpose' => ['required', 'string', 'min:5', 'max:2000'],
            'activity_description' => ['nullable', 'string', 'max:5000'],

            'destination_type' => ['required', Rule::in(FieldWorkDestinationType::values())],
            'destination_organization_id' => [$registered ? 'required' : 'nullable', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
            'destination_organization_unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')],
            'external_organization_name' => [$external ? 'required' : 'nullable', 'string', 'max:255'],
            'site_name' => [$site ? 'required' : 'nullable', 'string', 'max:255'],
            'destination_address' => [$external || $site ? 'required' : 'nullable', 'string', 'max:500'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{6,32}$/'],

            // Optional geofence: all three or none.
            'expected_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:expected_longitude,geofence_radius_m'],
            'expected_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:expected_latitude,geofence_radius_m'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:25', "max:{$maxRadius}", 'required_with:expected_latitude,expected_longitude'],

            'start_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'return_time' => ['required', 'date_format:H:i'],

            'participant_employee_ids' => ['nullable', 'array', 'max:'.app(FieldWorkSettings::class)->maxParticipants()],
            'participant_employee_ids.*' => ['uuid', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                [$start, $end] = $this->schedule();
                if (! $end->gt($start)) {
                    $validator->errors()->add('return_time', __('field-work.errors.return_before_start'));
                }
                if ($start->lt(now()->subDays(30))) {
                    $validator->errors()->add('start_date', __('field-work.errors.start_too_old'));
                }
                if ($start->diffInDays($end) > 90) {
                    $validator->errors()->add('return_date', __('field-work.errors.too_long'));
                }

                $unitId = $this->input('destination_organization_unit_id');
                if ($unitId && $this->input('destination_type') === FieldWorkDestinationType::RegisteredOrganization->value
                    && ! OrganizationUnit::query()->whereKey($unitId)->where('organization_id', $this->input('destination_organization_id'))->exists()) {
                    // Hierarchy-valid: the unit must belong to the chosen organization.
                    $validator->errors()->add('destination_organization_unit_id', __('field-work.errors.unit_not_in_organization'));
                }
            },
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} instants in the storage timezone */
    public function schedule(): array
    {
        $settings = app(FieldWorkSettings::class);

        return [
            $settings->instant((string) $this->input('start_date'), (string) $this->input('start_time')),
            $settings->instant((string) $this->input('return_date'), (string) $this->input('return_time')),
        ];
    }

    /** @return array<string, mixed> the shape FieldWorkService expects */
    public function payload(): array
    {
        [$start, $end] = $this->schedule();

        return [
            ...$this->safe()->except(['action', 'start_date', 'start_time', 'return_date', 'return_time']),
            'starts_at' => $start,
            'expected_return_at' => $end,
        ];
    }

    public function submitting(): bool
    {
        return $this->input('action') === 'submit';
    }
}
