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
        });
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
