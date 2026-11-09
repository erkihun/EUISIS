<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give each already-finalized Assessment record its immutable v1 result.
 *
 * This only inserts rows that do not yet have a current version. The unique
 * current_key constraint added by the governance migration makes the operation
 * safe to resume after an interrupted deployment; no historical record is
 * updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasRequiredTables()) {
            return;
        }

        DB::table('assessment_records as records')
            ->whereIn('records.status', ['reviewed', 'acknowledged'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('assessment_result_versions as versions')
                    ->whereColumn('versions.assessment_record_id', 'records.id')
                    ->where('versions.is_current', true);
            })
            ->orderBy('records.id')
            ->select([
                'records.id', 'records.percentage', 'records.contribution',
                'records.band_policy_id', 'records.band_code', 'records.band_label_en', 'records.band_label_am',
                'records.finalized_at', 'records.finalized_by', 'records.created_at',
            ])
            ->chunkById(500, function ($records): void {
                $now = now();
                $versions = $records->map(function (object $record) use ($now): array {
                    $effectiveAt = $record->finalized_at ?? $record->created_at ?? $now;

                    return [
                        'id' => (string) Str::uuid(),
                        'assessment_record_id' => $record->id,
                        'version_no' => 1,
                        'raw_score' => null,
                        'normalized_score' => $record->percentage,
                        'contribution_score' => $record->contribution,
                        'result_band_policy_id' => $record->band_policy_id,
                        'result_band_code' => $record->band_code,
                        'result_band_label_en' => $record->band_label_en,
                        'result_band_label_am' => $record->band_label_am,
                        'change_type' => 'original',
                        'supersedes_version_id' => null,
                        'effective_at' => $effectiveAt,
                        'created_by' => $record->finalized_by,
                        'decision_reference' => null,
                        'reason' => 'Historical finalized assessment backfill.',
                        'is_current' => true,
                        'current_key' => $record->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                DB::table('assessment_result_versions')->insertOrIgnore($versions);
            }, 'records.id', 'id');
    }

    public function down(): void
    {
        // Immutable historic result versions are intentionally never removed.
    }

    private function hasRequiredTables(): bool
    {
        return DB::getSchemaBuilder()->hasTable('assessment_records')
            && DB::getSchemaBuilder()->hasTable('assessment_result_versions');
    }
};
