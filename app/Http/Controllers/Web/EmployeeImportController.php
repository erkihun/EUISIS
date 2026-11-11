<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ServesImportFiles;
use App\Http\Controllers\Controller;
use App\Models\EmployeeImportBatch;
use App\Models\Organization;
use App\Services\Employees\EmployeeCsvImportService;
use App\Services\Employees\EmployeeImportFileException;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Scoped upload of employees from a CSV or Excel file.
 *
 * Three steps, each separately permissioned: open the page, upload and check,
 * then confirm. The middle step writes nothing to `employees`, so an importer
 * can inspect the check and walk away without consequence. After the
 * confirmation the same page shows the result, with the rows that were left
 * out ready to download.
 *
 * Every batch is re-checked for ownership on the later steps. A batch id is a
 * URL parameter, so it must never be enough on its own to view or commit
 * someone else's upload.
 */
class EmployeeImportController extends Controller
{
    use ServesImportFiles;

    /** The largest file the importer accepts, before the server's own upload limits. */
    private const MAX_FILE_KILOBYTES = 5120;

    /**
     * Seconds of importing per request. Short enough to finish inside PHP's
     * usual 30-second limit and a proxy's timeout; the page asks again until
     * no row is left.
     */
    private const IMPORT_SECONDS_PER_PASS = 10.0;

    /** Flashed between two passes of one import: the page then needs the counts only, not every row again. */
    private const BETWEEN_PASSES_KEY = 'employee_import_between_passes';

    public function __construct(
        private readonly EmployeeCsvImportService $importService,
        private readonly OrganizationScopeService $scope,
    ) {}

    /** The upload screen, plus the batch being checked or just imported, if there is one. */
    public function create(Request $request): Response
    {
        $this->authorizePermission('employees.import.view');

        $user = Auth::user();
        $batch = null;
        $rows = [];

        $batchId = $request->session()->get('employee_import_batch');

        if (is_string($batchId)) {
            $candidate = EmployeeImportBatch::query()->find($batchId);

            if ($candidate !== null && $this->ownsBatch($candidate)) {
                $batch = $this->batchPayload($candidate);
                // A 2,000-row list is about a megabyte; while the passes run the page shows the progress only.
                $betweenPasses = (bool) $request->session()->get(self::BETWEEN_PASSES_KEY) && $candidate->isInProgress();
                $rows = $betweenPasses ? [] : $this->importService->preview($candidate, app()->getLocale());
            }
        }

        return Inertia::render('Employees/ImportCsv', [
            'batch' => $batch,
            'rows' => $rows,
            'maxRows' => $this->importService->maxRows(),
            'maxFileBytes' => $this->maxUploadKilobytes(self::MAX_FILE_KILOBYTES) * 1024,
            // The organizations the actor may import into, for the template.
            'organizations' => $this->allowedOrganizations($user),
            'can' => [
                'upload' => $user?->can('employees.import.upload') ?? false,
                'confirm' => $user?->can('employees.import.confirm') ?? false,
                'viewEmployees' => $user?->can('employees.view') ?? false,
            ],
        ]);
    }

    /** Check an uploaded file and show the result. Nothing is saved to employees. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('employees.import.upload');

        $maxKilobytes = $this->maxUploadKilobytes(self::MAX_FILE_KILOBYTES);
        $tooLarge = __('employees.import.errors.tooLarge', ['max' => $this->formatMegabytes($maxKilobytes)]);

        $data = $request->validate([
            // `zip` because some servers report an Excel workbook as a plain
            // archive; the reader then refuses anything that is not a workbook.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,zip', 'max:'.$maxKilobytes],
            'calendar' => ['nullable', 'string', 'in:gregorian,ethiopian'],
        ], [
            'file.required' => __('employees.import.errors.noFile'),
            'file.file' => __('employees.import.errors.noFile'),
            'file.uploaded' => $tooLarge,
            'file.mimes' => __('employees.import.errors.notCsv'),
            'file.max' => $tooLarge,
        ]);

        // Reading and checking 2,000 rows of a workbook can take longer than the default limit.
        @set_time_limit(120);

        try {
            $batch = $this->importService->validate(
                $request->file('file'),
                Auth::user(),
                $request,
                $data['calendar'] ?? EmployeeCsvImportService::CALENDAR_GREGORIAN,
            );
        } catch (EmployeeImportFileException $e) {
            // The file as a whole could not be read: say why beside the file field.
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $request->session()->put('employee_import_batch', $batch->id);

        return to_route('employees.import.create')->with('flash', [
            'message' => __('employees.import.checked', [
                'valid' => $batch->valid_rows,
                'invalid' => $batch->failed_rows,
            ]),
            'type' => $batch->failed_rows > 0 || $batch->valid_rows === 0 ? 'warning' : 'success',
        ]);
    }

    /** Import the rows of a checked batch that passed. */
    public function confirm(Request $request, EmployeeImportBatch $batch): RedirectResponse
    {
        $this->authorizePermission('employees.import.confirm');

        abort_unless($this->ownsBatch($batch), 403);

        // The batch stays in the session so the page can show what happened
        // and offer the rows that were left out.
        $request->session()->put('employee_import_batch', $batch->id);

        // A second click or a second tab arriving after the import ended: show the result, do not complain.
        if ($batch->isFinished()) {
            return to_route('employees.import.create');
        }

        if (! $batch->isImportable()) {
            return back()->with('flash', [
                'message' => __('employees.import.notImportable'),
                'type' => 'error',
            ]);
        }

        @set_time_limit(120);

        try {
            $result = $this->importService->import($batch, Auth::user(), $request, self::IMPORT_SECONDS_PER_PASS);
        } catch (Throwable $e) {
            // This pass was rolled back; what earlier passes imported stays. A failure
            // may carry SQL, bindings or file paths: log it, never show it.
            report($e);

            return back()->with('flash', [
                'message' => __('employees.import.failed'),
                'type' => 'error',
            ]);
        }

        // Rows still waiting: the page sends the next pass by itself, so no message yet.
        if ($result['remaining'] > 0) {
            return to_route('employees.import.create')->with(self::BETWEEN_PASSES_KEY, true);
        }

        return to_route('employees.import.create')->with('flash', [
            'message' => __('employees.import.imported', ['count' => $result['total_imported']]),
            'type' => $result['total_imported'] > 0 ? 'success' : 'warning',
        ]);
    }

    /** The rows that were left out, to correct and upload again. */
    public function errors(EmployeeImportBatch $batch): HttpResponse
    {
        $this->authorizePermission('employees.import.view');

        abort_unless($this->ownsBatch($batch), 403);

        return response($this->importService->failedRowsCsv($batch), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employee-import-rows-to-fix.csv"',
        ]);
    }

    /** Discard the batch currently held in the session. */
    public function cancel(Request $request): RedirectResponse
    {
        $this->authorizePermission('employees.import.view');

        $request->session()->forget('employee_import_batch');

        return to_route('employees.import.create');
    }

    /** Download the template, as an Excel workbook or as CSV. */
    public function template(Request $request): HttpResponse
    {
        $this->authorizePermission('employees.import.view');

        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'locale' => ['nullable', 'string', 'in:en,am'],
            'format' => ['nullable', 'string', 'in:csv,xlsx'],
        ]);
        $user = Auth::user();
        $organization = Organization::query()
            ->where('status', 'active')
            ->when(! $this->scope->isUnrestricted($user), fn ($query) => $query->whereIn('id', $this->scope->accessibleOrganizationIds($user)->all()))
            ->findOrFail($data['organization_id']);

        $locale = $this->templateLocale($data['locale'] ?? null);
        $extension = ($data['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $disposition = $this->templateDisposition('employee-import', $organization, $locale, $extension);

        if ($extension === 'xlsx') {
            return response($this->importService->templateXlsx($organization, $locale), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => $disposition,
            ]);
        }

        return response($this->importService->templateCsv($organization, $locale), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $disposition,
        ]);
    }

    /**
     * Resolve the template locale.
     *
     * Priority: the locale the form submitted > the session locale > the app
     * default. The session fallback keeps a hand-built URL honouring whatever
     * language the user is actually browsing in.
     */
    private function templateLocale(?string $requested): string
    {
        $supported = ['en', 'am'];

        if (is_string($requested) && in_array($requested, $supported, true)) {
            return $requested;
        }

        $fromSession = session('locale');

        if (is_string($fromSession) && in_array($fromSession, $supported, true)) {
            return $fromSession;
        }

        return in_array(app()->getLocale(), $supported, true) ? app()->getLocale() : 'en';
    }

    /**
     * A batch belongs to the actor when they uploaded it, or when they may see
     * every organization anyway.
     */
    private function ownsBatch(EmployeeImportBatch $batch): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if ((string) $batch->uploaded_by === (string) $user->getKey()) {
            return true;
        }

        return $this->scope->isUnrestricted($user);
    }

    /** @return array<string, mixed> */
    private function batchPayload(EmployeeImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'file_name' => $batch->file_name,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'failed_rows' => $batch->failed_rows,
            'unfilled_rows' => $batch->unfilledRows(),
            'over_limit_rows' => $batch->overLimitRows(),
            'imported_rows' => $batch->importedRows(),
            'remaining_rows' => $batch->remainingRows(),
            'calendar' => (string) ($batch->error_summary['calendar'] ?? EmployeeCsvImportService::CALENDAR_GREGORIAN),
            'status' => $batch->status,
            'importable' => $batch->isImportable(),
            // True once the import ran to its last row, whether or not every row went in.
            'finished' => $batch->isFinished(),
            // True when an import started and rows still wait for the next pass.
            'in_progress' => $batch->isInProgress(),
        ];
    }

    /** Organization codes the actor may import into. */
    private function allowedOrganizations(mixed $user): array
    {
        return Organization::query()
            ->where('status', 'active')
            ->when(
                ! $this->scope->isUnrestricted($user),
                fn ($query) => $query->whereIn('id', $this->scope->accessibleOrganizationIds($user)->all()),
            )
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_am'])
            ->all();
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless(Auth::user()?->can($permission) ?? false, 403);
    }
}
