<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Positions\CreatePositionAction;
use App\Models\GradeLevel;
use App\Models\Occupation;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Positions of each demo organization, codes from the position code rule
 * (owner organization code / sequence). Occupations are the reference ones
 * of OccupationSeeder; grade levels are used only when the project has some —
 * none are invented.
 */
class DemoPositionSeeder extends DemoSeeder
{
    public function run(CreatePositionAction $createPosition): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $epoch = DemoDataset::epoch()->toDateString();
        $grades = GradeLevel::query()->orderBy('name')->pluck('name')->values();

        foreach (DemoDataset::businessOrganizationKeys() as $organizationKey) {
            $organization = DemoDataset::requireOrganization($organizationKey);

            DB::transaction(function () use ($organization, $organizationKey, $createPosition, $maker, $epoch, $grades): void {
                $index = 0;
                foreach (DemoDataset::positions($organizationKey) as $positionKey => $definition) {
                    if (DemoDataset::position($organization, $positionKey) !== null) {
                        $index++;

                        continue;
                    }

                    $unit = DemoDataset::unit($organization, $definition['unit'])
                        ?? throw new RuntimeException("Demo unit {$definition['unit']} of {$organizationKey} is missing.");

                    $createPosition->execute([
                        'organization_id' => $organization->id,
                        'organization_unit_id' => $unit->id,
                        'occupation_id' => $this->occupationId($definition['isco']),
                        'title_en' => $definition['title_en'],
                        'title_am' => $definition['title_am'],
                        'grade_level' => $grades->isEmpty() ? null : $grades[$index % $grades->count()],
                        'is_active' => true,
                        'effective_from' => $epoch,
                        'metadata' => DemoDataset::tag($positionKey, ['manager' => $definition['manager']]),
                    ], $maker);
                    $index++;
                }
            });
        }
    }

    private function occupationId(string $isco): string
    {
        return Occupation::query()->where('isco_code', $isco)->value('id')
            ?? Occupation::query()->where('is_active', true)->orderBy('code')->value('id')
            ?? throw new RuntimeException('No occupation exists; OccupationSeeder must run first.');
    }
}
