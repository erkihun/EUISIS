<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IsicActivity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the ISIC Rev. 4 structure (21 sections, 88 divisions, 238 groups and
 * 419 classes) into isic_activities from database/seeders/data/isic-rev4.csv.
 * The English names are the UN Statistics Division's published structure. The
 * file is in the column layout the classification import accepts (code,
 * parent_code, level, name_en, name_am), so the same dataset can be re-imported
 * from the Classifications screen.
 *
 * Idempotent on isic_code: a re-run creates nothing twice. A row that already
 * holds a code, archived ones included, keeps its recorded names and status, so
 * curated wording survives and archived rows are not brought back. It still
 * takes its place in the hierarchy, and a blank Amharic name is filled.
 */
class IsicActivitySeeder extends Seeder
{
    public const REVISION = 'Rev.4';

    private const LEVELS = ['section', 'division', 'group', 'class'];

    public function run(): void
    {
        $rows = $this->readDataset(__DIR__.'/data/isic-rev4.csv');

        DB::transaction(function () use ($rows): void {
            $existing = IsicActivity::withTrashed()->get()->keyBy('isic_code');
            $idsByCode = [];
            $sectionCode = null;

            foreach ($rows as $position => $row) {
                $parentId = null;
                if ($row['parent_code'] !== '') {
                    $parentId = $idsByCode[$row['parent_code']]
                        ?? throw new RuntimeException("isic-rev4.csv: parent {$row['parent_code']} of {$row['code']} must appear before its children.");
                }
                if ($row['level'] === 1) {
                    $sectionCode = $row['code'];
                }

                $activity = $existing->get($row['code'])
                    ?? new IsicActivity(['isic_code' => $row['code'], 'revision' => self::REVISION, 'is_active' => true]);

                $activity->fill([
                    'level' => self::LEVELS[$row['level'] - 1],
                    'section_code' => $sectionCode,
                    'division_code' => $row['level'] >= 2 ? substr($row['code'], 0, 2) : null,
                    'group_code' => $row['level'] >= 3 ? substr($row['code'], 0, 3) : null,
                    'class_code' => $row['level'] === 4 ? $row['code'] : null,
                    'parent_id' => $parentId,
                    'sort_order' => $position + 1,
                    'name_en' => $activity->name_en ?? $row['name_en'],
                    'name_am' => $activity->name_am ?? $row['name_am'],
                ]);
                $activity->save();

                $idsByCode[$row['code']] = $activity->getKey();
            }
        });
    }

    /**
     * @return list<array{code: string, parent_code: string, level: int, name_en: string, name_am: string}>
     */
    private function readDataset(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read ISIC dataset at {$path}.");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        $expected = ['code', 'parent_code', 'level', 'name_en', 'name_am'];
        if ($header !== $expected) {
            fclose($handle);
            throw new RuntimeException('isic-rev4.csv must start with the columns '.implode(',', $expected).'.');
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
                throw new RuntimeException("isic-rev4.csv: level of {$code} must be 1-4.");
            }
            $rows[] = ['code' => $code, 'parent_code' => $parent, 'level' => $level, 'name_en' => $nameEn, 'name_am' => $nameAm];
        }
        fclose($handle);

        return $rows;
    }
}
