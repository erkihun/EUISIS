<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IscoGroup;
use App\Models\Occupation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the ISCO-08 structure (10 major, 43 sub-major, 130 minor and 436 unit
 * groups) into isco_groups from database/seeders/data/isco-08.csv. The file is
 * in the same column layout the classification import accepts (code,
 * parent_code, level, name_en, name_am), so the same dataset can be re-imported
 * or extended from the Classifications screen. Reference rows are never typed
 * in by hand (D-12): this seeder only reads the dataset.
 *
 * Idempotent on (revision, code): a re-run refreshes names and parents and
 * creates nothing twice. Existing occupations whose recorded isco_code names
 * a unit group of this revision and which cite no group yet are linked to it.
 * Every unit group then gets an occupation of its own (436 in ISCO-08). An
 * occupation that already holds the code, archived ones included, is left as
 * it is, so curated names survive and archived rows are not brought back. The
 * exceptions are blank fields: an Amharic name is filled from the linked group,
 * a skill specialization from that group's minor group, because ISCO-08 forms
 * its minor groups by shared skill specialization, a skill level from the ILO's
 * rule (Occupation::iscoSkillLevel), and descriptions from
 * database/seeders/data/isco-08-unit-details.csv. Those descriptions are short
 * summaries written for this system, not the ILO's definitions.
 */
class IscoGroupSeeder extends Seeder
{
    public const REVISION = 'ISCO-08';

    public function run(): void
    {
        $rows = $this->readDataset(__DIR__.'/data/isco-08.csv');

        DB::transaction(function () use ($rows): void {
            $idsByCode = IscoGroup::query()->where('revision', self::REVISION)->pluck('id', 'code')->all();

            foreach ($rows as $row) {
                $parentId = null;
                if ($row['parent_code'] !== '') {
                    $parentId = $idsByCode[$row['parent_code']]
                        ?? throw new RuntimeException("isco-08.csv: parent {$row['parent_code']} of {$row['code']} must appear before its children.");
                }

                $group = IscoGroup::query()->firstOrNew(['revision' => self::REVISION, 'code' => $row['code']]);
                $group->fill([
                    'level' => IscoGroup::LEVELS[$row['level'] - 1],
                    'parent_id' => $parentId,
                    'name_en' => $row['name_en'],
                    'name_am' => $row['name_am'],
                    'is_active' => true,
                ]);
                $group->save();
                $idsByCode[$row['code']] = $group->getKey();
            }

            $this->linkOccupations();
            $this->createUnitGroupOccupations();
            $this->fillBlankAmharicNames();
            $this->fillBlankSkillSpecializations();
            $this->fillBlankSkillLevelsAndDescriptions();
        });
    }

    /** A value already recorded is never replaced, and an older single-language description stops the English one being added. */
    private function fillBlankSkillLevelsAndDescriptions(): void
    {
        $details = $this->readUnitDetails(__DIR__.'/data/isco-08-unit-details.csv');

        Occupation::query()->whereNotNull('isco_code')
            ->where(fn ($q) => $q->whereNull('skill_level')->orWhereNull('description_en')->orWhereNull('description_am'))
            ->get(['id', 'isco_code', 'skill_level', 'description', 'description_en', 'description_am'])
            ->each(function (Occupation $occupation) use ($details): void {
                $unit = $details[$occupation->isco_code] ?? null;
                Occupation::query()->whereKey($occupation->getKey())->update([
                    'skill_level' => $occupation->skill_level ?? Occupation::iscoSkillLevel($occupation->isco_code),
                    'description_en' => $occupation->description_en ?? ($occupation->description === null ? $unit['description_en'] ?? null : null),
                    'description_am' => $occupation->description_am ?? $unit['description_am'] ?? null,
                ]);
            });
    }

    /**
     * @return array<string, array{description_en: string, description_am: string}>
     */
    private function readUnitDetails(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read ISCO unit details at {$path}.");
        }

        $expected = ['code', 'description_en', 'description_am'];
        if (fgetcsv($handle, 0, ',', '"', '') !== $expected) {
            fclose($handle);
            throw new RuntimeException('isco-08-unit-details.csv must start with the columns '.implode(',', $expected).'.');
        }

        $details = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($line) === 3) {
                $details[(string) $line[0]] = ['description_en' => $line[1], 'description_am' => $line[2]];
            }
        }
        fclose($handle);

        return $details;
    }

    /** Each language blank takes the minor group's name in that language; a value already recorded is never replaced. */
    private function fillBlankSkillSpecializations(): void
    {
        Occupation::query()->whereNotNull('isco_group_id')
            ->where(fn ($q) => $q->whereNull('skill_specialization')->orWhereNull('skill_specialization_am'))
            ->with('iscoGroup.parent:id,name_en,name_am')
            ->get(['id', 'isco_group_id', 'skill_specialization', 'skill_specialization_am'])
            ->each(function (Occupation $occupation): void {
                $minorGroup = $occupation->iscoGroup?->parent;
                Occupation::query()->whereKey($occupation->getKey())->update([
                    'skill_specialization' => $occupation->skill_specialization ?? $minorGroup?->name_en,
                    'skill_specialization_am' => $occupation->skill_specialization_am ?? $minorGroup?->name_am,
                ]);
            });
    }

    /** A linked occupation with no Amharic name takes its group's; a name already recorded is never replaced. */
    private function fillBlankAmharicNames(): void
    {
        Occupation::query()->whereNull('name_am')->whereNotNull('isco_group_id')
            ->with('iscoGroup:id,name_am')
            ->get(['id', 'isco_group_id'])
            ->filter(fn (Occupation $occupation): bool => $occupation->iscoGroup?->name_am !== null)
            ->each(fn (Occupation $occupation) => Occupation::query()->whereKey($occupation->getKey())
                ->update(['name_am' => $occupation->iscoGroup->name_am]));
    }

    /** One occupation per unit group, carrying the group's names and its four ISCO codes. */
    private function createUnitGroupOccupations(): void
    {
        $taken = [];
        Occupation::withTrashed()->get(['code', 'isco_code'])->each(function (Occupation $occupation) use (&$taken): void {
            $taken[$occupation->code] = true;
            if ($occupation->isco_code !== null) {
                $taken[$occupation->isco_code] = true;
            }
        });

        IscoGroup::query()->where('revision', self::REVISION)->where('level', 'unit')->orderBy('code')->get()
            ->reject(fn (IscoGroup $unit): bool => isset($taken[$unit->code]))
            ->each(fn (IscoGroup $unit) => Occupation::query()->create([
                'code' => $unit->code,
                'isco_code' => $unit->code,
                'isco_group_id' => $unit->getKey(),
                'isco_major_group_code' => substr($unit->code, 0, 1),
                'isco_sub_major_group_code' => substr($unit->code, 0, 2),
                'isco_minor_group_code' => substr($unit->code, 0, 3),
                'isco_unit_group_code' => $unit->code,
                'name_en' => $unit->name_en,
                'name_am' => $unit->name_am,
                'is_active' => true,
            ]));
    }

    /** Occupations that record a unit-group code but cite no ISCO row yet are pointed at the seeded row. */
    private function linkOccupations(): void
    {
        $units = IscoGroup::query()->where('revision', self::REVISION)->where('level', 'unit')->pluck('id', 'code')->all();

        Occupation::query()->whereNull('isco_group_id')->whereNotNull('isco_code')
            ->whereIn('isco_code', array_keys($units))
            ->get(['id', 'isco_code'])
            ->each(fn (Occupation $occupation) => Occupation::query()->whereKey($occupation->getKey())
                ->update(['isco_group_id' => $units[$occupation->isco_code]]));
    }

    /**
     * @return list<array{code: string, parent_code: string, level: int, name_en: string, name_am: string|null}>
     */
    private function readDataset(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read ISCO dataset at {$path}.");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        $expected = ['code', 'parent_code', 'level', 'name_en', 'name_am'];
        if ($header !== $expected) {
            fclose($handle);
            throw new RuntimeException('isco-08.csv must start with the columns '.implode(',', $expected).'.');
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($line === [null] || trim((string) ($line[0] ?? '')) === '') {
                continue;
            }
            [$code, $parent, $level, $nameEn, $nameAm] = array_pad(array_map(fn ($v) => trim((string) $v), $line), 5, '');
            $level = (int) $level;
            if ($level < 1 || $level > 4) {
                fclose($handle);
                throw new RuntimeException("isco-08.csv: level of {$code} must be 1-4.");
            }
            $rows[] = ['code' => $code, 'parent_code' => $parent, 'level' => $level, 'name_en' => $nameEn, 'name_am' => $nameAm === '' ? null : $nameAm];
        }
        fclose($handle);

        return $rows;
    }
}
