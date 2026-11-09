<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Import;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Jobs\OrganizationDesign\ProcessDesignImportBatch;
use App\Models\DesignImportBatch;
use App\Models\DesignImportRow;
use App\Models\InstitutionMandate;
use App\Models\LegacyCodeMapping;
use App\Models\OrganizationFunction;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\User;
use App\Services\OrganizationDesign\FunctionService;
use App\Services\OrganizationDesign\MandateService;
use App\Services\OrganizationDesign\OrganizationDesignAudit;
use App\Services\OrganizationDesign\ProcessArchitectureService;
use App\Services\OrganizationDesign\ServiceCatalogService;
use App\Services\OrganizationDesign\TaskStandardService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staged imports of design catalogs (ADR-LB-004):
 *
 *   upload ─▶ parse (queued, chunked) ─▶ validate + match ─▶ [map] ─▶ commit
 *
 *  - rows are staged; nothing is authoritative before commit;
 *  - a row whose code already names a record of the organization is MATCHED,
 *    never created again; a legacy code remembered from an earlier run
 *    matches the same way, so re-running a file creates nothing twice;
 *  - a reference that resolves to nothing by code is MAPPING_REQUIRED and a
 *    person picks the record; a name-only resemblance is only a suggestion;
 *  - commit creates records through the ordinary domain services, one
 *    transaction per chunk, so every rule and audit entry applies.
 */
class DesignImportService
{
    public const DISK = 'local';

    public const PREFIX = 'DIB';

    public const ALLOWED_EXTENSIONS = ['xlsx', 'csv'];

    public const MAX_FILE_KB = 20480;

    public function __construct(
        private readonly OrganizationDesignAudit $audit,
        private readonly DesignImportReader $reader,
        private readonly MandateService $mandates,
        private readonly FunctionService $functions,
        private readonly ServiceCatalogService $services,
        private readonly ProcessArchitectureService $processes,
        private readonly TaskStandardService $standards,
    ) {}

    public function upload(User $actor, string $organizationId, string $entityType, UploadedFile $file, ?string $baselineId = null): DesignImportBatch
    {
        DesignImportDefinitions::for($entityType);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => __('organization-design.import_file_type')]);
        }

        $path = $file->storeAs('organization-design/imports/'.$organizationId, Str::uuid7().'.'.$extension, self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('organization-design.import_upload_failed')]);
        }

        try {
            $batch = DB::transaction(function () use ($actor, $organizationId, $entityType, $file, $path, $baselineId): DesignImportBatch {
                $batch = DesignImportBatch::query()->create([
                    'reference_number' => $this->nextReference(),
                    'organization_id' => $organizationId,
                    'entity_type' => $entityType,
                    'baseline_id' => $baselineId,
                    'file_name' => Str::limit(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $file->getClientOriginalName()) ?? 'import', 180, ''),
                    'file_disk' => self::DISK,
                    'file_path' => $path,
                    'fingerprint' => hash_file('sha256', $file->getRealPath()),
                    'status' => DesignImportBatch::STATUS_UPLOADED,
                    'uploaded_by' => $actor->getKey(),
                ]);
                $this->audit->record(AuditEventType::DesignImportUploaded, $actor, $batch, ['reference_number' => $batch->reference_number, 'entity_type' => $entityType, 'fingerprint' => $batch->fingerprint, 'size' => $file->getSize()]);

                return $batch;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }

        ProcessDesignImportBatch::dispatch($batch->getKey(), $actor->getKey());

        return $batch;
    }

    /** Parses the file into staged rows, then validates them. Runs in the queue. */
    public function parse(DesignImportBatch $batch, ?User $actor): void
    {
        $batch->forceFill(['status' => DesignImportBatch::STATUS_PARSING])->save();
        $definition = DesignImportDefinitions::for($batch->entity_type);
        $columns = array_keys($definition['columns']);
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);

        $headers = $this->reader->headers($batch->file_disk, $batch->file_path, $extension);
        $missing = array_values(array_filter(array_keys(array_filter($definition['columns'], fn ($c) => $c['required'] ?? false)), fn ($c) => ! in_array($c, $headers, true)));
        if ($missing !== []) {
            $batch->forceFill(['status' => DesignImportBatch::STATUS_FAILED, 'error_summary' => ['missing_columns' => $missing], 'parsed_at' => now()])->save();

            return;
        }

        $batch->rows()->delete();
        $buffer = [];
        $total = 0;
        foreach ($this->reader->rows($batch->file_disk, $batch->file_path, $extension, $columns) as $row) {
            $buffer[] = [
                'id' => (string) Str::uuid7(), 'batch_id' => $batch->getKey(), 'row_number' => $row['row'], 'data' => json_encode($row['data'], JSON_UNESCAPED_UNICODE),
                'status' => DesignImportRow::STATUS_PENDING, 'legacy_code' => $row['data']['legacy_code'] ?? null, 'updated_at' => now(),
            ];
            $total++;
            if (count($buffer) >= DesignImportReader::CHUNK) {
                DesignImportRow::query()->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            DesignImportRow::query()->insert($buffer);
        }
        $batch->forceFill(['total_rows' => $total, 'parsed_at' => now()])->save();

        $this->validate($batch, $actor);
    }

    /** Validates every staged row and matches it against existing records; sets the batch status. */
    public function validate(DesignImportBatch $batch, ?User $actor): void
    {
        $definition = DesignImportDefinitions::for($batch->entity_type);
        $codeColumn = $definition['code'];
        $seen = [];
        $counts = ['valid' => 0, 'invalid' => 0, 'mapping_required' => 0, 'matched' => 0];

        $existingByCode = $codeColumn ? $this->existingCodes($batch) : [];
        $legacyMap = LegacyCodeMapping::query()->where('organization_id', $batch->organization_id)->where('entity_type', $batch->entity_type)->pluck('canonical_id', 'legacy_code')->all();
        $referenceCache = [];

        $batch->rows()->whereNotIn('status', [DesignImportRow::STATUS_COMMITTED, DesignImportRow::STATUS_MATCHED])->chunkById(DesignImportReader::CHUNK, function ($rows) use ($definition, $codeColumn, &$seen, &$counts, $existingByCode, $legacyMap, &$referenceCache, $batch): void {
            foreach ($rows as $row) {
                $data = $row->data;
                $errors = [];
                $resolved = $row->resolved ?? [];
                $mappingRequired = false;

                foreach ($definition['columns'] as $column => $rule) {
                    $value = $data[$column] ?? null;
                    if (($rule['required'] ?? false) && ($value === null || $value === '')) {
                        $errors[] = ['field' => $column, 'message' => __('organization-design.import_errors.required', ['field' => $column])];

                        continue;
                    }
                    if ($value === null) {
                        continue;
                    }
                    $error = $this->checkType($column, $value, $rule);
                    if ($error !== null) {
                        $errors[] = $error;
                    }
                }

                if ($codeColumn && ($code = $data[$codeColumn] ?? null) !== null) {
                    $key = mb_strtoupper($code);
                    if (isset($seen[$key])) {
                        $errors[] = ['field' => $codeColumn, 'message' => __('organization-design.import_errors.duplicate_in_file', ['code' => $code, 'row' => $seen[$key]])];
                    }
                    $seen[$key] = $row->row_number;
                }

                foreach ($definition['references'] as $column => $reference) {
                    $value = $data[$column] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    if (isset($resolved[$column]) && $row->match_source === 'manual') {
                        continue; // A person already chose; keep it.
                    }
                    $codes = ($reference['multiple'] ?? false) ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [$value];
                    $ids = [];
                    $unresolved = [];
                    foreach ($codes as $refCode) {
                        $id = $this->resolveReference($batch, $reference, $refCode, $referenceCache);
                        if ($id === null) {
                            $unresolved[] = $refCode;
                        } else {
                            $ids[] = $id;
                        }
                    }
                    if ($unresolved !== []) {
                        $mappingRequired = true;
                        $errors[] = ['field' => $column, 'message' => __('organization-design.import_errors.unresolved_reference', ['field' => $column, 'code' => implode(', ', $unresolved)]), 'suggestions' => $this->suggest($batch, $reference, $unresolved[0])];
                    } else {
                        $resolved[$column] = ($reference['multiple'] ?? false) ? $ids : $ids[0];
                    }
                }

                // Own code already names a record: match, never duplicate.
                $matchedId = null;
                $matchSource = null;
                if ($codeColumn && ($code = $data[$codeColumn] ?? null) !== null && isset($existingByCode[mb_strtoupper($code)])) {
                    $matchedId = $existingByCode[mb_strtoupper($code)];
                    $matchSource = 'code';
                } elseif (($legacy = $row->legacy_code) !== null && isset($legacyMap[$legacy])) {
                    $matchedId = $legacyMap[$legacy];
                    $matchSource = 'legacy_code';
                }

                $status = $errors !== [] ? ($mappingRequired && count($errors) === count(array_filter($errors, fn ($e) => isset($e['suggestions']))) ? DesignImportRow::STATUS_MAPPING_REQUIRED : DesignImportRow::STATUS_INVALID) : DesignImportRow::STATUS_VALID;
                $counts[$status === DesignImportRow::STATUS_VALID ? 'valid' : ($status === DesignImportRow::STATUS_MAPPING_REQUIRED ? 'mapping_required' : 'invalid')]++;
                if ($matchedId !== null && $status === DesignImportRow::STATUS_VALID) {
                    $counts['matched']++;
                }

                $row->forceFill([
                    'status' => $status, 'errors' => $errors ?: null, 'resolved' => $resolved ?: null,
                    'matched_type' => $matchedId ? $this->modelClass($batch->entity_type) : null, 'matched_id' => $matchedId, 'match_source' => $matchedId ? $matchSource : ($row->match_source === 'manual' ? 'manual' : null),
                    'updated_at' => now(),
                ])->save();
            }
        });

        $committed = $batch->rows()->whereIn('status', [DesignImportRow::STATUS_COMMITTED, DesignImportRow::STATUS_MATCHED])->count();
        $status = $batch->status === DesignImportBatch::STATUS_COMMITTED ? DesignImportBatch::STATUS_COMMITTED : ($counts['mapping_required'] > 0 ? DesignImportBatch::STATUS_MAPPING_REQUIRED : DesignImportBatch::STATUS_VALIDATED);
        $batch->forceFill([
            'status' => $status, 'valid_rows' => $counts['valid'], 'invalid_rows' => $counts['invalid'], 'mapping_required_rows' => $counts['mapping_required'], 'matched_rows' => $counts['matched'] + $committed,
            'error_summary' => null,
        ])->save();
        $this->audit->record(AuditEventType::DesignImportValidated, $actor, $batch, ['valid' => $counts['valid'], 'invalid' => $counts['invalid'], 'mapping_required' => $counts['mapping_required'], 'matched' => $counts['matched']]);
    }

    /** A person resolves one reference column of one row to a canonical record. */
    public function mapRow(User $actor, DesignImportRow $row, string $column, string $canonicalId): DesignImportRow
    {
        $batch = $row->batch;
        $definition = DesignImportDefinitions::for($batch->entity_type);
        $reference = $definition['references'][$column] ?? null;
        if ($reference === null) {
            throw ValidationException::withMessages(['column' => __('organization-design.import_map_column_unknown')]);
        }
        $model = $reference['model'];
        $record = $model::query()->whereKey($canonicalId)->first();
        if ($record === null || ($reference['scope'] !== 'global' && $record->getAttribute('organization_id') !== null && $record->getAttribute('organization_id') !== $batch->organization_id)) {
            throw ValidationException::withMessages(['canonical_id' => __('organization-design.import_map_record_invalid')]);
        }

        return DB::transaction(function () use ($actor, $row, $batch, $column, $reference, $canonicalId): DesignImportRow {
            $resolved = $row->resolved ?? [];
            $resolved[$column] = ($reference['multiple'] ?? false) ? [$canonicalId] : $canonicalId;
            $errors = array_values(array_filter($row->errors ?? [], fn ($e) => ($e['field'] ?? null) !== $column));
            $row->forceFill(['resolved' => $resolved, 'errors' => $errors ?: null, 'match_source' => 'manual', 'status' => $errors === [] ? DesignImportRow::STATUS_VALID : $row->status, 'updated_at' => now()])->save();
            $data = $row->data;
            if (($data[$column] ?? null) !== null) {
                LegacyCodeMapping::query()->updateOrCreate(
                    ['organization_id' => $batch->organization_id, 'entity_type' => $reference['label'], 'legacy_code' => (string) $data[$column]],
                    ['canonical_type' => (new $reference['model'])->getMorphClass(), 'canonical_id' => $canonicalId, 'source' => 'manual', 'created_by' => $actor->getKey(), 'created_at' => now()],
                );
            }
            $this->audit->record(AuditEventType::DesignImportMapped, $actor, $batch, ['row' => $row->row_number, 'column' => $column, 'canonical_id' => $canonicalId]);
            $this->recount($batch);

            return $row;
        });
    }

    /** Creates draft records from every valid row, one transaction per chunk; matched rows are linked, never recreated. */
    public function commit(User $actor, DesignImportBatch $batch): DesignImportBatch
    {
        if (! in_array($batch->status, [DesignImportBatch::STATUS_VALIDATED, DesignImportBatch::STATUS_MAPPING_REQUIRED], true)) {
            throw ValidationException::withMessages(['status' => __('organization-design.import_commit_status')]);
        }
        if ($batch->rows()->where('status', DesignImportRow::STATUS_MAPPING_REQUIRED)->exists()) {
            throw ValidationException::withMessages(['status' => __('organization-design.import_commit_mapping_pending')]);
        }

        $batch->forceFill(['status' => DesignImportBatch::STATUS_COMMITTING])->save();
        $created = 0;
        $matched = 0;
        $failed = [];
        $batch->rows()->where('status', DesignImportRow::STATUS_VALID)->orderBy('row_number')->chunkById(100, function ($rows) use ($actor, $batch, &$created, &$matched, &$failed): void {
            DB::transaction(function () use ($rows, $actor, $batch, &$created, &$matched, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        if ($row->matched_id !== null) {
                            $this->rememberLegacyCode($actor, $batch, $row, $row->matched_type, $row->matched_id);
                            $row->forceFill(['status' => DesignImportRow::STATUS_MATCHED, 'updated_at' => now()])->save();
                            $matched++;

                            continue;
                        }
                        $record = $this->createRecord($actor, $batch, $row);
                        $this->rememberLegacyCode($actor, $batch, $row, $record->getMorphClass(), (string) $record->getKey());
                        $row->forceFill(['status' => DesignImportRow::STATUS_COMMITTED, 'created_type' => $record->getMorphClass(), 'created_id' => $record->getKey(), 'updated_at' => now()])->save();
                        $created++;
                    } catch (ValidationException $e) {
                        $row->forceFill(['status' => DesignImportRow::STATUS_INVALID, 'errors' => collect($e->errors())->map(fn ($messages, $field) => ['field' => $field, 'message' => implode(' ', $messages)])->values()->all(), 'updated_at' => now()])->save();
                        $failed[] = $row->row_number;
                    }
                }
            });
        });

        $batch->forceFill(['status' => DesignImportBatch::STATUS_COMMITTED, 'committed_rows' => $created, 'matched_rows' => $matched, 'invalid_rows' => $batch->rows()->where('status', DesignImportRow::STATUS_INVALID)->count(), 'valid_rows' => 0, 'committed_by' => $actor->getKey(), 'committed_at' => now(), 'error_summary' => $failed ? ['failed_rows' => $failed] : null])->save();
        $this->audit->record(AuditEventType::DesignImportCommitted, $actor, $batch, ['created' => $created, 'matched' => $matched, 'failed' => count($failed)]);

        return $batch;
    }

    public function cancel(User $actor, DesignImportBatch $batch, ?string $reason): DesignImportBatch
    {
        if (in_array($batch->status, [DesignImportBatch::STATUS_COMMITTED, DesignImportBatch::STATUS_COMMITTING], true)) {
            throw ValidationException::withMessages(['status' => __('organization-design.import_cancel_blocked')]);
        }
        $batch->forceFill(['status' => DesignImportBatch::STATUS_CANCELLED])->save();
        Storage::disk($batch->file_disk)->delete($batch->file_path);
        $this->audit->record(AuditEventType::DesignImportCancelled, $actor, $batch, [], null, $reason);

        return $batch;
    }

    /** The bilingual CSV template for a kind: column keys as the header, labels on a comment row the importer skips (it is blank in the key column). */
    public function templateCsv(string $entityType, string $locale): string
    {
        $definition = DesignImportDefinitions::for($entityType);
        $columns = array_keys($definition['columns']);
        $out = "\xEF\xBB\xBF".implode(',', $columns)."\r\n";
        $labels = array_map(fn (string $c) => str_replace('"', '""', ($definition['columns'][$c]['required'] ?? false ? '* ' : '').__('organization-design.import_columns.'.$c, [], $locale)), $columns);
        $example = array_map(fn (string $c) => '', $columns);

        return $out.'"'.implode('","', $labels).'"'."\r\n".implode(',', $example)."\r\n";
    }

    // ── internals ────────────────────────────────────────────────────────

    private function createRecord(User $actor, DesignImportBatch $batch, DesignImportRow $row): Model
    {
        $data = $row->data;
        $resolved = $row->resolved ?? [];
        $orgId = $batch->organization_id;

        return match ($batch->entity_type) {
            'mandates' => $this->mandates->createDraft($actor, $orgId, [
                ...$this->pick($data, ['code', 'title_en', 'title_am', 'description_en', 'description_am', 'legal_instrument_number', 'legal_reference', 'article_reference', 'issuing_authority', 'effective_from', 'effective_to']),
                'legal_instrument_type_id' => $resolved['legal_instrument_type_code'], 'legal_source_id' => $resolved['legal_source_code'] ?? null,
            ]),
            'functions' => $this->functions->createFunction($actor, InstitutionMandate::query()->findOrFail($resolved['mandate_code']), [
                ...$this->pick($data, ['code', 'title_en', 'title_am', 'description_en', 'description_am', 'legal_reference', 'effective_from', 'effective_to']),
                'sequence' => (int) ($data['sequence'] ?? 0), 'power_id' => $resolved['power_code'] ?? null, 'parent_function_id' => $resolved['parent_function_code'] ?? null,
            ]),
            'services' => $this->services->createDraft($actor, $orgId, [
                ...$this->pick($data, ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'beneficiary_type', 'delivery_channel', 'legal_basis', 'effective_from', 'effective_to']),
                'service_type_id' => $resolved['service_type_code'] ?? null, 'owner_organization_unit_id' => $resolved['owner_unit_code'] ?? null, 'function_ids' => $resolved['function_codes'] ?? [],
            ]),
            'processes' => $this->processes->createDraft($actor, $orgId, [
                ...$this->pick($data, ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'effective_from', 'effective_to']),
                'service_id' => $resolved['service_code'], 'process_type' => $data['process_type'] ?? 'core', 'owner_organization_unit_id' => $resolved['owner_unit_code'] ?? null,
            ]),
            'activities' => $this->processes->createActivity($actor, Process::query()->findOrFail($resolved['process_code']), [
                ...$this->pick($data, ['code', 'name_en', 'name_am', 'description_en', 'description_am']),
                'sequence' => (int) ($data['sequence'] ?? 0), 'parent_activity_id' => $resolved['parent_activity_code'] ?? null, 'is_active' => true,
            ]),
            'tasks' => $this->processes->createTask($actor, ProcessActivity::query()->findOrFail($resolved['activity_code']), [
                ...$this->pick($data, ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'required_output']),
                'sequence' => (int) ($data['sequence'] ?? 0), 'is_active' => true,
            ]),
            'work_standards' => $this->standards->createDraft($actor, ProcessTask::query()->findOrFail($resolved['task_code']), [
                ...$this->pick($data, ['standard_measure', 'bpr_reference', 'planned_quantity', 'quantity_unit', 'planned_time_minutes', 'planned_quality', 'quality_unit', 'quality_measure', 'quality_source', 'effective_from', 'effective_to']),
            ]),
            default => throw new \InvalidArgumentException('Unknown import type.'),
        };
    }

    private function rememberLegacyCode(User $actor, DesignImportBatch $batch, DesignImportRow $row, string $type, string $id): void
    {
        if ($row->legacy_code === null) {
            return;
        }
        LegacyCodeMapping::query()->updateOrCreate(
            ['organization_id' => $batch->organization_id, 'entity_type' => $batch->entity_type, 'legacy_code' => $row->legacy_code],
            ['canonical_type' => $type, 'canonical_id' => $id, 'source' => 'import', 'created_by' => $actor->getKey(), 'created_at' => now()],
        );
    }

    /** @return array<string, string> upper-cased code => id, for the batch's kind inside its organization */
    private function existingCodes(DesignImportBatch $batch): array
    {
        $model = $this->modelClass($batch->entity_type);
        $query = $model::query()->where('organization_id', $batch->organization_id);

        return $query->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (string) $id])->all();
    }

    private function modelClass(string $entityType): string
    {
        return match ($entityType) {
            'mandates' => InstitutionMandate::class,
            'functions' => OrganizationFunction::class,
            'services' => Service::class,
            'processes' => Process::class,
            'activities' => ProcessActivity::class,
            'tasks' => ProcessTask::class,
            'work_standards' => PositionServiceTaskStandard::class,
            default => throw new \InvalidArgumentException('Unknown import type.'),
        };
    }

    private function resolveReference(DesignImportBatch $batch, array $reference, string $code, array &$cache): ?string
    {
        $key = $reference['model'].'|'.mb_strtoupper($code);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $model = $reference['model'];
        $query = $model::query()->where($reference['column'], $code);
        if ($reference['scope'] === 'organization') {
            $query->where('organization_id', $batch->organization_id);
        } elseif ($reference['scope'] === 'citable') {
            $query->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $batch->organization_id));
        }
        $id = $query->value('id');
        if ($id === null) {
            // A legacy code remembered for that kind.
            $id = LegacyCodeMapping::query()->where('organization_id', $batch->organization_id)->where('entity_type', $reference['label'])->where('legacy_code', $code)->value('canonical_id');
        }

        return $cache[$key] = $id === null ? null : (string) $id;
    }

    /** Up to five records whose code or name resembles the unresolved value: a hint for the mapping screen, never applied by itself. */
    private function suggest(DesignImportBatch $batch, array $reference, string $code): array
    {
        $model = $reference['model'];
        $query = $model::query();
        if ($reference['scope'] === 'organization') {
            $query->where('organization_id', $batch->organization_id);
        } elseif ($reference['scope'] === 'citable') {
            $query->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $batch->organization_id));
        }
        $like = '%'.mb_substr($code, 0, 20).'%';
        $nameColumn = in_array('name_en', (new $model)->getFillable(), true) ? 'name_en' : 'title_en';
        $query->where(fn ($q) => $q->where($reference['column'], ci_like_operator(), $like)->orWhere($nameColumn, ci_like_operator(), $like));

        return $query->limit(5)->get(['id', $reference['column'], $nameColumn])->map(fn ($r) => ['id' => $r->getKey(), 'code' => $r->getAttribute($reference['column']), 'name' => $r->getAttribute($nameColumn)])->all();
    }

    private function checkType(string $column, string $value, array $rule): ?array
    {
        $type = $rule['type'] ?? 'string';
        $fail = fn (string $key, array $params = []) => ['field' => $column, 'message' => __('organization-design.import_errors.'.$key, ['field' => $column, ...$params])];

        return match ($type) {
            'code' => preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $value) !== 1 || mb_strlen($value) > ($rule['max'] ?? 40) ? $fail('code_format', ['max' => $rule['max'] ?? 40]) : null,
            'date' => $this->isDate($value) ? null : $fail('date_format'),
            'integer' => preg_match('/^-?\d+$/', $value) !== 1 ? $fail('integer') : null,
            'decimal' => is_numeric($value) ? null : $fail('decimal'),
            default => (isset($rule['max']) && mb_strlen($value) > $rule['max']) ? $fail('too_long', ['max' => $rule['max']]) : ((isset($rule['in']) && ! in_array($value, $rule['in'], true)) ? $fail('not_allowed', ['allowed' => implode(', ', $rule['in'])]) : null),
        };
    }

    private function isDate(string $value): bool
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $value) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function recount(DesignImportBatch $batch): void
    {
        $byStatus = $batch->rows()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $mapping = (int) ($byStatus[DesignImportRow::STATUS_MAPPING_REQUIRED] ?? 0);
        $batch->forceFill([
            'valid_rows' => (int) ($byStatus[DesignImportRow::STATUS_VALID] ?? 0), 'invalid_rows' => (int) ($byStatus[DesignImportRow::STATUS_INVALID] ?? 0), 'mapping_required_rows' => $mapping,
            'status' => $batch->status === DesignImportBatch::STATUS_COMMITTED ? $batch->status : ($mapping > 0 ? DesignImportBatch::STATUS_MAPPING_REQUIRED : DesignImportBatch::STATUS_VALIDATED),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function pick(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /** From the design-import code rule (DIB-YYYY-NNNN by default). */
    private function nextReference(): string
    {
        return app(GenerateCodeAction::class)->execute(CodeRuleEntityType::DesignImportBatch, [], null, null, 'reference_number');
    }
}
