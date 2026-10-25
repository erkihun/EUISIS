<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The employee fields a card template may print, as EUISIS
 * IdCardFieldImpactService::ROW_FIELDS names them (row key => employee
 * column). EUISIS keeps the list in a service that reads its print
 * snapshots, which the Core does not have; the copied template request
 * reads the list from here instead.
 */
final class IdCardFields
{
    public const ROW_FIELDS = ['name' => 'name', 'sex' => 'gender', 'dob' => 'date_of_birth', 'nationality' => 'nationality', 'employment' => 'employment_type', 'phone' => 'phone', 'idNumber' => 'employee_number', 'email' => 'email', 'address' => 'address', 'emergency_contact_name' => 'emergency_contact_name', 'emergency_contact_phone' => 'emergency_contact_phone'];
}
