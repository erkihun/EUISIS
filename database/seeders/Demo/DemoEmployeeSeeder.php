<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Employees\RegisterEmployeeAction;
use App\Enums\EmployeeStatus;
use App\Support\Demo\DemoDataset;
use RuntimeException;

/**
 * Employees with their current assignment (organization → unit → position),
 * registered through RegisterEmployeeAction: employee number from the code
 * rule, one active occupant per position under a row lock, encrypted national
 * ID with its hash, and the feedback QR token.
 */
class DemoEmployeeSeeder extends DemoSeeder
{
    public function run(RegisterEmployeeAction $register): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $epoch = DemoDataset::epoch()->toDateString();

        foreach (DemoDataset::employees() as $key => $definition) {
            if (DemoDataset::employee($key) !== null) {
                continue;
            }

            $organization = DemoDataset::requireOrganization($definition['organization']);
            $position = DemoDataset::position($organization, $definition['position'])
                ?? throw new RuntimeException("Demo position {$definition['position']} of {$definition['organization']} is missing.");

            $register->execute([
                'first_name' => $definition['first_name'],
                'middle_name' => $definition['middle_name'],
                'last_name' => $definition['last_name'],
                'full_name' => "{$definition['first_name']} {$definition['middle_name']} {$definition['last_name']}",
                'name_en' => $definition['name_en'],
                'email' => $definition['email'],
                'gender' => $definition['gender'],
                'date_of_birth' => $definition['date_of_birth'],
                'nationality' => 'Ethiopian',
                'employment_type' => $definition['employment_type'],
                'national_id' => $definition['national_id'],
                'preferred_language' => 'am',
                'status' => EmployeeStatus::Active,
                'metadata' => DemoDataset::tag($key),
            ], [
                'organization_id' => $organization->id,
                'organization_unit_id' => $position->organization_unit_id,
                'position_id' => $position->id,
                'effective_from' => $epoch,
                'reason' => 'Synthetic demo employee',
            ], $maker);
        }
    }
}
