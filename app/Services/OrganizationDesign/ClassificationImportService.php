<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Models\ClassificationImportBatch;
use App\Models\IscoGroup;
use App\Models\IsicActivity;
use App\Models\ServiceClassification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled import of classification reference data (brief §14–15, 96–97):
 * LOCAL and CPC service classifications, and ISIC activities. CSV in,
 * validated row by row; a dry run reports what would happen and writes
 * nothing; a real run is idempotent on (scheme, revision, code) and never
 * touches another revision's rows. Codes are never invented here: the file
 * is the official dataset.
 */
class ClassificationImportService
{
    public const DATASETS = ['LOCAL', 'CPC', 'ISIC', 'ISCO'];

    private const MAX_ROWS = 20000;

    private const COLUMNS = ['code', 'parent_code', 'level', 'name_en', 'name_am'];

    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    public function import(User $actor, string $dataset, string $revision, UploadedFile $file, bool $dryRun): ClassificationImportBatch
    {
        $dataset = strtoupper($dataset);
        if (! in_array($dataset, self::DATASETS, true)) {
            throw ValidationException::withMessages(['scheme' => __('organization-design.import_unknown_dataset')]);
        }
        [$rows, $errors] = $this->parse($file);

        return DB::transaction(function () use ($actor, $dataset, $revision, $file, $dryRun, $rows, $errors): ClassificationImportBatch {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $byCode = [];

            foreach ($rows as $row) {
                $line = $row['line'];
                $parentId = null;
                if ($row['parent_code'] !== '') {
                    $parentId = $byCode[$row['parent_code']] ?? $this->findParent($dataset, $revision, $row['parent_code']);
                    if ($parentId === null) {
                        $errors[] = ['line' => $line, 'column' => 'parent_code', 'message' => __('organization-design.import_parent_unknown', ['code' => $row['parent_code']])];
                        continue;
                    }
                }
                $outcome = $dryRun ? $this->simulate($dataset, $revision, $row) : $this->write($dataset, $revision, $row, $parentId);
                $counts[$outcome['result']]++;
                $byCode[$row['code']] = $outcome['id'] ?? ('dry:'.$row['code']);
            }

            $batch = ClassificationImportBatch::query()->create([
                'dataset' => $dataset, 'revision' => $revision, 'file_name' => $file->getClientOriginalName(),
                'fingerprint' => hash_file('sha256', $file->getRealPath()), 'dry_run' => $dryRun,
                'total_rows' => count($rows) + count(array_filter($errors, fn ($e) => $e['column'] !== 'file')),
                'created_rows' => $counts['created'], 'updated_rows' => $counts['updated'], 'unchanged_rows' => $counts['unchanged'],
                'failed_rows' => count($errors), 'status' => $dryRun ? 'previewed' : ($errors ? 'completed_with_errors' : 'completed'),
                'error_report' => array_slice($errors, 0, 500), 'uploaded_by' => $actor->getKey(),
            ]);
            $this->audit->record(AuditEventType::ClassificationImported, $actor, $batch, $batch->only(['dataset', 'revision', 'dry_run', 'created_rows', 'updated_rows', 'failed_rows']));

            return $batch;
        });
    }

    /**
     * @return array{0: list<array{line: int, code: string, parent_code: string, level: int, name_en: string, name_am: string|null}>, 1: list<array{line: int, column: string, message: string}>}
     */
    private function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => __('organization-design.import_unreadable')]);
        }
        $header = fgetcsv($handle);
        if ($header === false) {
            throw ValidationException::withMessages(['file' => __('organization-design.import_unreadable')]);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);
        $missing = array_diff(self::COLUMNS, $header);
        if ($missing) {
            throw ValidationException::withMessages(['file' => __('organization-design.import_missing_columns', ['columns' => implode(', ', $missing)])]);
        }
        $index = array_flip($header);
        $rows = [];
        $errors = [];
        $line = 1;
        $seen = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($line > self::MAX_ROWS + 1) {
                $errors[] = ['line' => $line, 'column' => 'file', 'message' => __('organization-design.import_too_many_rows', ['max' => self::MAX_ROWS])];
                break;
            }
            if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                continue;
            }
            $get = fn (string $col) => trim((string) ($cells[$index[$col]] ?? ''));
            $code = $get('code');
            $nameEn = $get('name_en');
            $level = $get('level');
            if ($code === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,39}$/', $code)) {
                $errors[] = ['line' => $line, 'column' => 'code', 'message' => __('organization-design.import_invalid_code', ['code' => $code])];
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = ['line' => $line, 'column' => 'code', 'message' => __('organization-design.import_duplicate_code', ['code' => $code])];
                continue;
            }
            if ($nameEn === '' || mb_strlen($nameEn) > 255) {
                $errors[] = ['line' => $line, 'column' => 'name_en', 'message' => __('organization-design.import_name_required')];
                continue;
            }
            if ($level !== '' && ! ctype_digit($level)) {
                $errors[] = ['line' => $line, 'column' => 'level', 'message' => __('organization-design.import_invalid_level')];
                continue;
            }
            $seen[$code] = true;
            $rows[] = ['line' => $line, 'code' => $code, 'parent_code' => $get('parent_code'), 'level' => $level === '' ? 1 : (int) $level, 'name_en' => $nameEn, 'name_am' => $get('name_am') ?: null];
        }
        fclose($handle);

        return [$rows, $errors];
    }

    private function findParent(string $dataset, string $revision, string $parentCode): ?string
    {
        if ($dataset === 'ISIC') {
            return IsicActivity::query()->where('revision', $revision)->where('isic_code', $parentCode)->value('id');
        }
        if ($dataset === 'ISCO') {
            return IscoGroup::query()->where('revision', $revision)->where('code', $parentCode)->value('id');
        }

        return ServiceClassification::query()->where('scheme', $dataset)->where('revision', $revision)->where('code', $parentCode)->value('id');
    }

    /** @return array{result: string, id?: string} */
    private function simulate(string $dataset, string $revision, array $row): array
    {
        $existing = match ($dataset) {
            'ISIC' => IsicActivity::query()->where('revision', $revision)->where('isic_code', $row['code'])->first(),
            'ISCO' => IscoGroup::query()->where('revision', $revision)->where('code', $row['code'])->first(),
            default => ServiceClassification::query()->where('scheme', $dataset)->where('revision', $revision)->where('code', $row['code'])->first(),
        };
        if ($existing === null) {
            return ['result' => 'created'];
        }
        $changed = $existing->name_en !== $row['name_en'] || ($existing->name_am ?? null) !== $row['name_am'];

        return ['result' => $changed ? 'updated' : 'unchanged', 'id' => $existing->getKey()];
    }

    /** @return array{result: string, id: string} */
    private function write(string $dataset, string $revision, array $row, ?string $parentId): array
    {
        if ($dataset === 'ISIC') {
            $model = IsicActivity::query()->firstOrNew(['revision' => $revision, 'isic_code' => $row['code']]);
            $wasNew = ! $model->exists;
            $levelName = ['section', 'division', 'group', 'class'][min(max($row['level'], 1), 4) - 1];
            $model->fill(['name_en' => $row['name_en'], 'name_am' => $row['name_am'], 'level' => $levelName, 'parent_id' => $parentId, 'is_active' => true]);
        } elseif ($dataset === 'ISCO') {
            $model = IscoGroup::query()->firstOrNew(['revision' => $revision, 'code' => $row['code']]);
            $wasNew = ! $model->exists;
            $levelName = IscoGroup::LEVELS[min(max($row['level'], 1), 4) - 1];
            $model->fill(['name_en' => $row['name_en'], 'name_am' => $row['name_am'], 'level' => $levelName, 'parent_id' => $parentId, 'is_active' => true]);
        } else {
            $model = ServiceClassification::query()->firstOrNew(['scheme' => $dataset, 'revision' => $revision, 'code' => $row['code']]);
            $wasNew = ! $model->exists;
            $model->fill(['name_en' => $row['name_en'], 'name_am' => $row['name_am'], 'level' => $row['level'], 'parent_id' => $parentId]);
        }
        $dirty = $wasNew || $model->isDirty();
        $model->save();

        return ['result' => $wasNew ? 'created' : ($dirty ? 'updated' : 'unchanged'), 'id' => (string) $model->getKey()];
    }
}
