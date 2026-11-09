<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Exports\PublicFeedbackSubmissionsExport;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SituationRoom\CommunityVoiceQuery;
use App\Services\SituationRoom\SituationRoomFilters;
use App\Services\SituationRoom\SituationRoomScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Modules\Feedback\Contracts\FeedbackReportExports;
use Modules\Feedback\Contracts\FeedbackVisibility;
use Modules\Feedback\Contracts\ReadModels\FeedbackReportExportRecord;
use Throwable;

/**
 * Builds a Situation Room institutions export on the queue, as the user who
 * asked for it — their scope and target-type grants decide what is counted.
 * Aggregate rows only: institution names and counts, never submission text
 * or identity. Strings go through csv_safe_row() in the export class.
 */
class ExportSituationRoomReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const REPORT = 'situation_room_institutions';

    public const COLUMNS = ['code', 'institution', 'total', 'feedback', 'suggestion', 'complaint', 'unresolved', 'resolved', 'unrouted', 'unresolved_complaints'];

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $exportId) {}

    public function handle(CommunityVoiceQuery $voice, OrganizationScopeService $scopes, FeedbackVisibility $visibility, FeedbackReportExports $exports, WriteAuditLogAction $audit): void
    {
        $export = FeedbackReportExportRecord::query()->find($this->exportId);

        if ($export === null || $export->status !== 'queued') {
            return;
        }

        $requester = $export->requester;

        if ($requester === null || ! $requester->can('situation_room.export')) {
            $exports->markFailed($export->getKey(), 'requester missing or no longer allowed');

            return;
        }

        try {
            $stored = $export->filters ?? [];
            $scope = SituationRoomScope::for($requester, $export->organization_id, $scopes, $visibility);
            // The period as it was when requested, not as it would be today.
            $filters = SituationRoomFilters::fromInput(['period' => 'custom'] + $stored);
            $result = $voice->byInstitution($scope, $filters);
            // Headings and names in the language the requester was using.
            $am = ($stored['locale'] ?? null) === 'am';
            app()->setLocale($am ? 'am' : 'en');

            $rows = array_map(fn (array $row): array => [
                'code' => $row['code'],
                'institution' => $am ? ($row['name_am'] ?: $row['name_en']) : ($row['name_en'] ?: $row['name_am']),
            ] + $row, $result['rows']);

            if ($scope->node !== null) {
                $rows[] = [
                    'code' => $scope->node->code,
                    'institution' => __('situation-room.recorded_at_itself', ['name' => $am ? ($scope->node->name_am ?: $scope->node->name_en) : ($scope->node->name_en ?: $scope->node->name_am)]),
                ] + $result['own'];
            } elseif ($result['own']['total'] > 0) {
                $rows[] = ['code' => null, 'institution' => __('situation-room.recorded_elsewhere')] + $result['own'];
            }

            $labels = collect(self::COLUMNS)->mapWithKeys(fn (string $column): array => [$column => (string) __('situation-room.export_columns.'.$column)])->all();
            $csv = $export->format === 'csv';
            $filename = 'situation-room-institutions-'.$filters->start->format('Ymd').'-'.$filters->end->copy()->subDay()->format('Ymd').'.'.$export->format;
            $path = 'situation-room/exports/'.$export->id.'.'.$export->format;

            ExcelFacade::store(new PublicFeedbackSubmissionsExport(self::COLUMNS, $labels, $rows, $csv), $path, $export->disk, $csv ? Excel::CSV : Excel::XLSX);

            $exports->markReady($export->getKey(), $path, $filename, count($rows));
            $export->refresh();

            $audit->execute(
                eventType: AuditEventType::SituationRoomExportRequested,
                actor: $requester,
                auditable: $export,
                organizationId: $export->organization_id,
                newValues: ['report' => self::REPORT, 'format' => $export->format, 'rows' => count($rows), 'filters' => $filters->toArray(), 'status' => 'ready'],
                reason: 'Situation Room institutions export built',
            );
        } catch (Throwable $exception) {
            $exports->markFailed($export->getKey(), $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(FeedbackReportExports::class)->markFailed($this->exportId, $exception->getMessage());
    }
}
