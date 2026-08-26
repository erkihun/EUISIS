<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeeImportBatch;
use App\Models\Organization;
use App\Services\Employees\EmployeeCsvImportService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Scoped CSV upload of employees.
 *
 * Three steps, each separately permissioned: open the page, upload and validate,
 * then confirm. The middle step writes nothing to `employees`, so an importer
 * can inspect a preview and walk away without consequence.
 *
 * Every batch is re-checked for ownership on the later steps. A validated batch
 * id is a URL parameter, so it must never be enough on its own to view or
 * commit someone else's upload.
 */
class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeCsvImportService $importService,
        private readonly OrganizationScopeService $scope,
    ) {}

    /** The upload screen, plus the batch being previewed if there is one. */
    public function create(Request $request): Response
    {
        $this->authorizePermission('employees.import.view');

        $user = Auth::user();
        $batch = null;
        $preview = [];

        $batchId = $request->session()->get('employee_import_batch');

        if (is_string($batchId)) {
            $candidate = EmployeeImportBatch::query()->find($batchId);

            if ($candidate !== null && $this->ownsBatch($candidate)) {
                $batch = $this->batchPayload($candidate);
                $preview = $this->importService->preview($candidate);
            }
        }

        return Inertia::render('Employees/ImportCsv', [
            'batch' => $batch,
            'preview' => $preview,
            'columns' => EmployeeCsvImportService::COLUMNS,
            // Shown so an importer knows which organization codes are usable.
            'allowedOrganizations' => $this->allowedOrganizations($user),
            'can' => [
                'upload' => $user?->can('employees.import.upload') ?? false,
                'confirm' => $user?->can('employees.import.confirm') ?? false,
            ],
        ]);
    }

    /** Validate an uploaded file and show the preview. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('employees.import.upload');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.mimes' => __('employees.import.errors.notCsv'),
        ]);

        $batch = $this->importService->validate($request->file('file'), Auth::user(), $request);

        $request->session()->put('employee_import_batch', $batch->id);

        return to_route('employees.import.create')->with('flash', [
            'message' => $batch->failed_rows > 0
                ? __('employees.import.validatedWithErrors', ['invalid' => $batch->failed_rows])
                : __('employees.import.validatedClean', ['valid' => $batch->valid_rows]),
            'type' => $batch->failed_rows > 0 ? 'warning' : 'success',
        ]);
    }

    /** Commit a validated batch. */
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
        } catch (Throwable $e) {
            /*
             * The service rolls back on any failure, so nothing was written.
             * The message names the offending row, which is what an importer
             * needs to fix the file.
             */
            return back()->with('flash', [
                'message' => __('employees.import.failed', ['reason' => $e->getMessage()]),
                'type' => 'error',
            ]);
        }

        $request->session()->forget('employee_import_batch');

        return to_route('employees.index')->with('flash', [
            'message' => __('employees.import.imported', ['count' => $result['imported']]),
            'type' => 'success',
        ]);
    }

    /** Discard the batch currently held in the session. */
    public function cancel(Request $request): RedirectResponse
    {
        $this->authorizePermission('employees.import.view');

        $request->session()->forget('employee_import_batch');

        return to_route('employees.import.create');
    }

    /** Download the CSV template. */
    public function template(): HttpResponse
    {
        $this->authorizePermission('employees.import.view');

        return response($this->importService->templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employee-import-template.csv"',
        ]);
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
            'status' => $batch->status,
            'importable' => $batch->isImportable(),
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
