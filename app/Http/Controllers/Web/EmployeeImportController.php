<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeeImportBatch;
use App\Models\Organization;
use App\Services\Employees\EmployeeCsvImportService;
use App\Services\Employees\EmployeeImportFileException;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
                $rows = $this->importService->preview($candidate, app()->getLocale());
            }
        }

        return Inertia::render('Employees/ImportCsv', [
            'batch' => $batch,
            'rows' => $rows,
            'maxRows' => $this->importService->maxRows(),
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

        $data = $request->validate([
            // `zip` because some servers report an Excel workbook as a plain
            // archive; the reader then refuses anything that is not a workbook.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,zip', 'max:5120'],
            'calendar' => ['nullable', 'string', 'in:gregorian,ethiopian'],
        ], [
            'file.required' => __('employees.import.errors.noFile'),
            'file.file' => __('employees.import.errors.noFile'),
            'file.uploaded' => __('employees.import.errors.tooLarge'),
            'file.mimes' => __('employees.import.errors.notCsv'),
            'file.max' => __('employees.import.errors.tooLarge'),
        ]);

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

        if (! $batch->isImportable()) {
            return back()->with('flash', [
                'message' => __('employees.import.notImportable'),
                'type' => 'error',
            ]);
        }

        try {
            $result = $this->importService->import($batch, Auth::user(), $request);
        } catch (QueryException $e) {
            // A database failure carries SQL, bindings and connection details: log it, never show it.
            report($e);

            return back()->with('flash', [
                'message' => trim(__('employees.import.failed', ['reason' => ''])),
                'type' => 'error',
            ]);
        } catch (Throwable $e) {
            // The service rolls back on any failure, so nothing was written.
            return back()->with('flash', [
                'message' => __('employees.import.failed', ['reason' => $e->getMessage()]),
                'type' => 'error',
            ]);
        }

        // The batch stays in the session so the page can show what happened
        // and offer the rows that were left out.
        $request->session()->put('employee_import_batch', $batch->id);

        return to_route('employees.import.create')->with('flash', [
            'message' => __('employees.import.imported', ['count' => $result['imported']]),
            'type' => $result['imported'] > 0 ? 'success' : 'warning',
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
        $fileName = 'employee-import-'.Str::slug($organization->code).'-template-'.$locale;

        if (($data['format'] ?? 'csv') === 'xlsx') {
            return response($this->importService->templateXlsx($organization, $locale), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'.xlsx"',
            ]);
        }

        return response($this->importService->templateCsv($organization, $locale), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'.csv"',
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
            'calendar' => (string) ($batch->error_summary['calendar'] ?? EmployeeCsvImportService::CALENDAR_GREGORIAN),
            'status' => $batch->status,
            'importable' => $batch->isImportable(),
            // True once the confirmation ran, whether or not every row went in.
            'finished' => array_key_exists('imported', $batch->error_summary ?? []),
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
