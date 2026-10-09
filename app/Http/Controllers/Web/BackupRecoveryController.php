<?php

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\BackupOperation;
use App\Models\BackupRestoreRequest;
use App\Services\Backup\BackupStatusService;
use App\Services\Backup\RestoreWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BackupRecoveryController extends Controller
{
    private const PUBLIC_POLICY = ['retention_full_count', 'stale_hours', 'critical_hours', 'full_stale_days', 'verify_stale_days',
        'restore_test_days', 'wal_lag_seconds', 'storage_warning_percent', 'logical_enabled', 'logical_stale_days', 'repository_names', 'pitr_required'];

    public function index(Request $request, BackupStatusService $service)
    {
        $actor = $request->user();
        abort_unless($actor->can('backups.view_status'), 403);
        app(WriteAuditLogAction::class)->execute(AuditEventType::BackupStatusViewed, $actor, request: $request);
        $status = $actor->can('backups.view_history') ? $service->status() : $service->summary();
        // Process diagnostics are for operators on the server (backup:health -v), not the browser.
        $status['diagnostics'] = [];
        $approver = $actor->can('backups.restore_approve');
        // Without the backup tables (migration not applied) the status reports HISTORY_UNAVAILABLE
        // instead of the page failing.
        try {
            $history = $actor->can('backups.view_history') ? BackupOperation::query()->latest('started_at')->limit(50)->get() : [];
            $requests = BackupRestoreRequest::query()
                ->with(['requester:id,name', 'reviewer:id,name', 'approver:id,name', 'productionAuthorizer:id,name'])
                ->when(! $approver && ! $actor->can('backups.restore_review'), fn ($query) => $query->where('requested_by', $actor->id))
                ->latest()->limit(50)->get()
                ->map(fn (BackupRestoreRequest $record) => [
                    ...$record->only(['id', 'restore_type', 'incident_reference', 'reason', 'backup_reference', 'status', 'evidence_reference', 'failure_summary', 'requested_by']),
                    ...collect(['target_time', 'started_at', 'completed_at', 'created_at'])->mapWithKeys(fn ($key) => [$key => $record->{$key}?->toIso8601String()]),
                    'requester' => $record->requester?->name, 'reviewer' => $record->reviewer?->name,
                    'approver' => $record->approver?->name, 'production_authorizer' => $record->productionAuthorizer?->name,
                    'can_cancel' => in_array($record->status, ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'TEST_RESTORE_VERIFIED', 'PRODUCTION_RESTORE_AUTHORIZED'], true)
                        && ($approver || ($actor->can('backups.restore_request') && $record->requested_by === $actor->id)),
                ]);
        } catch (QueryException) {
            [$history, $requests] = [[], []];
        }

        return Inertia::render('System/BackupRecovery', [
            'status' => $status,
            'history' => $history,
            'requests' => $requests,
            'can' => ['request' => $actor->can('backups.restore_request'), 'review' => $actor->can('backups.restore_review'), 'approve' => $approver],
            'policy' => array_intersect_key(config('backup'), array_flip(self::PUBLIC_POLICY)),
        ]);
    }

    /** Re-probe now instead of waiting for the short status cache to expire. Rate limited. */
    public function refresh(Request $request, BackupStatusService $service)
    {
        abort_unless($request->user()->can('backups.view_status'), 403);
        $service->health(fresh: true);

        return back();
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('backups.restore_request'), 403);
        $data = $request->validate([
            'restore_type' => ['required', Rule::in(BackupRestoreRequest::TYPES)],
            'incident_reference' => ['required', 'regex:'.RestoreWorkflow::EVIDENCE],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'target_time' => ['required_if:restore_type,POINT_IN_TIME', 'prohibited_unless:restore_type,POINT_IN_TIME', 'nullable', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'backup_reference' => ['required_if:restore_type,BACKUP_SET', 'prohibited_unless:restore_type,BACKUP_SET', 'nullable', 'regex:/\A\d{8}-\d{6}F(?:_\d{8}-\d{6}[DI])?\z/'],
        ]);
        DB::transaction(function () use ($request, $data) {
            $record = BackupRestoreRequest::create([...$data, 'requested_by' => $request->user()->id]);
            app(WriteAuditLogAction::class)->execute(AuditEventType::RestoreRequested, $request->user(), $record, newValues: $data, request: $request);
        });

        return back();
    }

    public function transition(Request $request, BackupRestoreRequest $restoreRequest, RestoreWorkflow $workflow)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(RestoreWorkflow::BROWSER_ACTIONS)],
            'evidence_reference' => ['nullable', 'regex:'.RestoreWorkflow::EVIDENCE],
        ]);
        $workflow->transition($restoreRequest, $data['action'], $request->user(), $data['evidence_reference'] ?? null);

        return back();
    }
}
