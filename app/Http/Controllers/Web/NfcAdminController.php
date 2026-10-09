<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\NfcCredential;
use App\Models\NfcVerificationLog;
use App\Models\Organization;
use App\Models\ServiceTerminal;
use App\Services\Nfc\NfcAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only NFC administration screens.
 *
 * Lifecycle changes live in NfcManagementController, next to the ID card they
 * belong to; this controller only reports state. Every listing is gated on the
 * permission AND narrowed by organization scope — the permission opens the
 * page, the scope decides what is in it.
 */
class NfcAdminController extends Controller
{
    /** Credential states, in lifecycle order, used for KPI tiles and filters. */
    private const STATUSES = ['pending', 'active', 'suspended', 'lost', 'revoked', 'replaced', 'expired'];

    public function __construct(private readonly NfcAccess $access) {}

    public function dashboard(Request $request): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_credentials.view'), 403);

        $organizationIds = $this->access->organizationScope($request->user());
        $today = Carbon::today();

        // One grouped query per concern rather than a count per status.
        $byStatus = $this->credentialQuery($organizationIds)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $todayByResult = $this->logQuery($organizationIds)
            ->whereDate('occurred_at', $today)
            ->toBase()
            ->selectRaw('result, count(*) as aggregate')
            ->groupBy('result')
            ->pluck('aggregate', 'result');

        return Inertia::render('NfcManagement/Dashboard', [
            'summary' => [
                'credentials' => (int) $byStatus->sum(),
                'statuses' => collect(self::STATUSES)
                    ->mapWithKeys(fn (string $status) => [$status => (int) ($byStatus[$status] ?? 0)])
                    ->all(),
                'activeTerminals' => (int) $this->terminalQuery($organizationIds)->where('status', 'active')->count(),
                'verificationsToday' => (int) $todayByResult->sum(),
                'allowedToday' => (int) ($todayByResult['allowed'] ?? 0),
                'blockedToday' => (int) ($todayByResult['blocked'] ?? 0),
            ],
            'statusDistribution' => collect(self::STATUSES)
                ->map(fn (string $status) => ['key' => $status, 'value' => (int) ($byStatus[$status] ?? 0)])
                ->filter(fn (array $row) => $row['value'] > 0)
                ->values()
                ->all(),
            'trend' => $this->buildTrend($organizationIds, $today),
            'terminalTypes' => $this->terminalQuery($organizationIds)
                ->toBase()
                ->selectRaw('terminal_type, count(*) as aggregate')
                ->groupBy('terminal_type')
                ->get()
                ->map(fn ($row) => ['key' => (string) $row->terminal_type, 'value' => (int) $row->aggregate])
                ->all(),
            'can' => $this->capabilities($request),
        ]);
    }

    public function credentials(Request $request): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_credentials.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'organization_id' => ['nullable', 'uuid'],
            'credential_type' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'card_status' => ['nullable', 'string', 'max:40'],
            'issued_from' => ['nullable', 'date_format:Y-m-d'],
            'last_used_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $organizationIds = $this->access->organizationScope($request->user());

        $credentials = $this->credentialQuery($organizationIds)
            ->with([
                'idCard:id,employee_id,card_number,status,expires_at',
                'idCard.employee:id,employee_number,full_name,current_assignment_id',
                'idCard.employee.currentAssignment:id,organization_id',
                'idCard.employee.currentAssignment.organization:id,name_en,name_am',
            ])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $nested) => $nested
                    ->whereLike('credential_id', "%{$search}%", caseSensitive: false)
                    ->orWhereHas('idCard', fn (Builder $card) => $card->whereLike('card_number', "%{$search}%", caseSensitive: false))
                    ->orWhereHas('idCard.employee', fn (Builder $employee) => $employee
                        ->whereLike('employee_number', "%{$search}%", caseSensitive: false)
                        ->orWhereLike('full_name', "%{$search}%", caseSensitive: false))
            ))
            ->when($filters['organization_id'] ?? null, fn (Builder $query, string $id) => $query->whereHas(
                'idCard.employee.currentAssignment',
                fn (Builder $assignment) => $assignment->where('organization_id', $id),
            ))
            ->when($filters['credential_type'] ?? null, fn (Builder $query, string $type) => $query->where('credential_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['card_status'] ?? null, fn (Builder $query, string $status) => $query->whereHas(
                'idCard',
                fn (Builder $card) => $card->where('status', $status),
            ))
            ->when($filters['issued_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issued_at', '>=', $date))
            ->when($filters['last_used_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('last_used_at', '>=', $date))
            ->orderByDesc('issued_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NfcCredential $credential) => $this->credentialRow($credential));

        return Inertia::render('NfcManagement/Credentials/Index', [
            'credentials' => $this->paginated($credentials),
            'filters' => $filters,
            'organizations' => $this->organizationOptions($organizationIds),
            'statuses' => self::STATUSES,
            'can' => $this->capabilities($request),
        ]);
    }

    public function credential(Request $request, string $credential): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_credentials.view'), 403);

        $organizationIds = $this->access->organizationScope($request->user());

        // Scope is part of the lookup, so an out-of-scope credential is a 404
        // rather than a 403 — the existence of the record stays private.
        $model = $this->credentialQuery($organizationIds)
            ->with([
                'idCard:id,employee_id,card_number,status,issued_at,expires_at',
                'idCard.employee:id,employee_number,full_name,current_assignment_id',
                'idCard.employee.currentAssignment:id,organization_id,organization_unit_id,position_id',
                'idCard.employee.currentAssignment.organization:id,name_en,name_am',
                'idCard.employee.currentAssignment.organizationUnit:id,name_en,name_am',
                'idCard.employee.currentAssignment.position:id,title_en,title_am',
                'createdBy:id,name',
                'revokedBy:id,name',
                'replacedBy:id,credential_id',
            ])
            ->where('credential_id', $credential)
            ->firstOrFail();

        $activity = NfcVerificationLog::query()
            ->where('nfc_credential_id', $model->id)
            ->with(['terminal:id,terminal_code,name,terminal_type,service_type', 'externalApplication:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (NfcVerificationLog $log) => $this->logRow($log))
            ->all();

        $assignment = $model->idCard?->employee?->currentAssignment;

        return Inertia::render('NfcManagement/Credentials/Show', [
            'credential' => array_merge($this->credentialRow($model), [
                'activated_at' => $model->activated_at?->toIso8601String(),
                'expires_at' => $model->expires_at?->toIso8601String(),
                'revoked_at' => $model->revoked_at?->toIso8601String(),
                'created_by' => $model->createdBy?->name,
                'revoked_by' => $model->revokedBy?->name,
                'replaced_by' => $model->replacedBy?->credential_id,
            ]),
            'card' => $model->idCard ? [
                'id' => $model->idCard->id,
                'card_number' => $model->idCard->card_number,
                'status' => $model->idCard->status?->value,
                'issued_at' => $model->idCard->issued_at?->toIso8601String(),
                'expires_at' => $model->idCard->expires_at?->toIso8601String(),
            ] : null,
            'employee' => $model->idCard?->employee ? [
                'employee_number' => $model->idCard->employee->employee_number,
                'full_name' => $model->idCard->employee->full_name,
                'organization' => $assignment?->organization?->only(['name_en', 'name_am']),
                'organization_unit' => $assignment?->organizationUnit?->only(['name_en', 'name_am']),
                'position' => $assignment?->position?->only(['title_en', 'title_am']),
            ] : null,
            'lifecycle' => $this->lifecycle($model),
            'activity' => $activity,
            'can' => $this->capabilities($request),
        ]);
    }

    public function logs(Request $request): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_logs.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'organization_id' => ['nullable', 'uuid'],
            'terminal_id' => ['nullable', 'uuid'],
            'result' => ['nullable', 'string', 'max:24'],
            'reason_code' => ['nullable', 'string', 'max:64'],
            'service_type' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $organizationIds = $this->access->organizationScope($request->user());

        $logs = $this->logQuery($organizationIds)
            ->with([
                'nfcCredential:id,credential_id,id_card_id',
                'nfcCredential.idCard:id,employee_id,card_number',
                'nfcCredential.idCard.employee:id,employee_number,full_name',
                'terminal:id,terminal_code,name,terminal_type,service_type',
                'externalApplication:id,name',
            ])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->whereHas(
                'nfcCredential',
                fn (Builder $credential) => $credential
                    ->whereLike('credential_id', "%{$search}%", caseSensitive: false)
                    ->orWhereHas('idCard.employee', fn (Builder $employee) => $employee
                        ->whereLike('employee_number', "%{$search}%", caseSensitive: false)
                        ->orWhereLike('full_name', "%{$search}%", caseSensitive: false)),
            ))
            ->when($filters['organization_id'] ?? null, fn (Builder $query, string $id) => $query->whereHas(
                'nfcCredential.idCard.employee.currentAssignment',
                fn (Builder $assignment) => $assignment->where('organization_id', $id),
            ))
            ->when($filters['terminal_id'] ?? null, fn (Builder $query, string $id) => $query->where('terminal_id', $id))
            ->when($filters['result'] ?? null, fn (Builder $query, string $result) => $query->where('result', $result))
            ->when($filters['reason_code'] ?? null, fn (Builder $query, string $code) => $query->where('reason_code', $code))
            ->when($filters['service_type'] ?? null, fn (Builder $query, string $type) => $query->whereHas(
                'terminal',
                fn (Builder $terminal) => $terminal->where('service_type', $type),
            ))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '<=', $date))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (NfcVerificationLog $log) => $this->logRow($log));

        return Inertia::render('NfcManagement/Logs/Index', [
            'logs' => $this->paginated($logs),
            'filters' => $filters,
            'organizations' => $this->organizationOptions($organizationIds),
            'terminals' => $this->terminalQuery($organizationIds)
                ->orderBy('terminal_code')
                ->get(['id', 'terminal_code', 'name'])
                ->all(),
            'can' => $this->capabilities($request),
        ]);
    }

    /**
     * Projection sent to the client. Deliberately excludes chip_uid_hash and
     * key_reference; key_version is an opaque label and safe to show.
     */
    private function credentialRow(NfcCredential $credential): array
    {
        $employee = $credential->idCard?->employee;

        return [
            'credential_id' => $credential->credential_id,
            'credential_type' => $credential->credential_type,
            'status' => $credential->status,
            'key_version' => $credential->key_version,
            'issued_at' => $credential->issued_at?->toIso8601String(),
            'last_used_at' => $credential->last_used_at?->toIso8601String(),
            'card' => $credential->idCard ? [
                'id' => $credential->idCard->id,
                'card_number' => $credential->idCard->card_number,
                'status' => $credential->idCard->status?->value,
            ] : null,
            'employee' => $employee ? [
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
            ] : null,
            'organization' => $employee?->currentAssignment?->organization?->only(['name_en', 'name_am']),
        ];
    }

    private function logRow(NfcVerificationLog $log): array
    {
        $employee = $log->nfcCredential?->idCard?->employee;

        return [
            'id' => $log->id,
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'event_type' => $log->event_type,
            'result' => $log->result,
            'reason_code' => $log->reason_code,
            'credential_id' => $log->nfcCredential?->credential_id,
            'employee' => $employee ? [
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
            ] : null,
            'terminal' => $log->terminal?->only(['id', 'terminal_code', 'name', 'terminal_type', 'service_type']),
            'external_application' => $log->externalApplication?->name,
        ];
    }

    /**
     * Lifecycle milestones held on the credential row itself. Verification
     * traffic is reported separately so the history stays short and readable.
     *
     * @return list<array{event: string, at: string, actor: string|null}>
     */
    private function lifecycle(NfcCredential $credential): array
    {
        $events = [
            ['event' => 'provisioned', 'at' => $credential->issued_at, 'actor' => $credential->createdBy?->name],
            ['event' => 'activated', 'at' => $credential->activated_at, 'actor' => null],
            ['event' => $credential->status, 'at' => $credential->revoked_at, 'actor' => $credential->revokedBy?->name],
        ];

        return collect($events)
            ->filter(fn (array $event) => $event['at'] !== null)
            ->map(fn (array $event) => [
                'event' => (string) $event['event'],
                'at' => $event['at']->toIso8601String(),
                'actor' => $event['actor'],
            ])
            ->values()
            ->all();
    }

    /**
     * Fourteen-day allowed/blocked series, zero-filled so the chart has a point
     * for every day rather than only the days with traffic.
     *
     * @return list<array{day: string, allowed: int, blocked: int}>
     */
    private function buildTrend(Collection $organizationIds, Carbon $today): array
    {
        $rows = $this->logQuery($organizationIds)
            ->whereDate('occurred_at', '>=', $today->copy()->subDays(13))
            ->toBase()
            ->selectRaw('date(occurred_at) as day, result, count(*) as aggregate')
            ->groupBy('day', 'result')
            ->get()
            ->groupBy(fn ($row) => substr((string) $row->day, 0, 10));

        return collect(range(13, 0))
            ->map(function (int $daysAgo) use ($rows, $today): array {
                $day = $today->copy()->subDays($daysAgo)->toDateString();
                $forDay = $rows->get($day) ?? collect();

                return [
                    'day' => $day,
                    'allowed' => (int) (optional($forDay->firstWhere('result', 'allowed'))->aggregate ?? 0),
                    'blocked' => (int) (optional($forDay->firstWhere('result', 'blocked'))->aggregate ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function credentialQuery(Collection $organizationIds): Builder
    {
        return NfcCredential::query()->when(
            $organizationIds->isNotEmpty(),
            fn (Builder $query) => $query->whereHas(
                'idCard.employee.currentAssignment',
                fn (Builder $assignment) => $assignment->whereIn('organization_id', $organizationIds),
            ),
        );
    }

    private function logQuery(Collection $organizationIds): Builder
    {
        return NfcVerificationLog::query()->when(
            $organizationIds->isNotEmpty(),
            fn (Builder $query) => $query->whereHas(
                'nfcCredential.idCard.employee.currentAssignment',
                fn (Builder $assignment) => $assignment->whereIn('organization_id', $organizationIds),
            ),
        );
    }

    private function terminalQuery(Collection $organizationIds): Builder
    {
        return ServiceTerminal::query()->when(
            $organizationIds->isNotEmpty(),
            fn (Builder $query) => $query->whereIn('organization_id', $organizationIds),
        );
    }

    private function organizationOptions(Collection $organizationIds): array
    {
        return Organization::query()
            ->select(['id', 'name_en', 'name_am'])
            ->when($organizationIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $organizationIds))
            ->orderBy('name_en')
            ->get()
            ->all();
    }

    /**
     * Laravel serialises a paginator with its metadata at the top level; the
     * client expects `data` plus a compact `meta`, matching AppDataTable.
     *
     * @return array{data: array<int, mixed>, meta: array<string, int>}
     */
    private function paginated(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, bool> */
    private function capabilities(Request $request): array
    {
        $user = $request->user();

        return [
            'viewCredentials' => $this->access->allowsListing($user, 'nfc_credentials.view'),
            'viewLogs' => $this->access->allowsListing($user, 'nfc_logs.view'),
            'viewTerminals' => $this->access->allowsListing($user, 'nfc_terminals.view'),
            'createTerminals' => $this->access->allowsListing($user, 'nfc_terminals.create'),
            'updateTerminals' => $this->access->allowsListing($user, 'nfc_terminals.update'),
            'deleteTerminals' => $this->access->allowsListing($user, 'nfc_terminals.delete'),
        ];
    }
}
