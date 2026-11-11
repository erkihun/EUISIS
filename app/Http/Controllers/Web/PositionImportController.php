<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ServesImportFiles;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionImportBatch;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Positions\PositionImportFileException;
use App\Services\Positions\PositionImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Scoped upload of positions from a CSV or Excel file.
 *
 * The same three steps as the employee import: open the page, upload and
 * check, then confirm. The check writes nothing to `positions`. Whoever may
 * create a position by hand may import them; there is no separate permission.
 *
 * A batch id is a URL parameter, so ownership is re-checked on every later
 * step: it must never be enough on its own to view or commit someone else's
 * upload.
 */
class PositionImportController extends Controller
{
    use ServesImportFiles;

    private const SESSION_KEY = 'position_import_batch';

    /** The largest file the importer accepts, before the server's own upload limits. */
    private const MAX_FILE_KILOBYTES = 5120;

    /**
     * Seconds of importing per request. Short enough to finish inside PHP's
     * usual 30-second limit and a proxy's timeout; the page asks again until
     * no row is left.
     */
    private const IMPORT_SECONDS_PER_PASS = 10.0;

    /** Flashed between two passes of one import: the page then needs the counts only, not every row again. */
    private const BETWEEN_PASSES_KEY = 'position_import_between_passes';

    public function __construct(
        private readonly PositionImportService $importService,
        private readonly OrganizationScopeService $scope,
    ) {}

    /** The upload screen, plus the batch being checked or just imported, if there is one. */
    public function create(Request $request): Response
    {
        $this->authorize('create', Position::class);

        $batch = null;
        $rows = [];
        $batchId = $request->session()->get(self::SESSION_KEY);

        if (is_string($batchId)) {
            $candidate = PositionImportBatch::query()->find($batchId);

            if ($candidate !== null && $this->ownsBatch($candidate)) {
                // A 2,000-row list is about a megabyte; while the passes run the page shows the progress only.
                $betweenPasses = (bool) $request->session()->get(self::BETWEEN_PASSES_KEY) && $candidate->isInProgress();
                $rows = $betweenPasses ? [] : $this->importService->preview($candidate, app()->getLocale());
                $batch = $this->batchPayload($candidate) + [
                    'existing_rows' => count(array_filter($rows, static fn (array $row): bool => $row['already_exists'])),
                ];
            }
        }

        return Inertia::render('Positions/Import', [
            'batch' => $batch,
            'rows' => $rows,
            'maxRows' => $this->importService->maxRows(),
            'maxFileBytes' => $this->maxFileKilobytes() * 1024,
            // The organizations the actor may import into, for the template.
            'organizations' => $this->allowedOrganizations()->get(['id', 'code', 'name_en', 'name_am'])->all(),
        ]);
    }

    /** Check an uploaded file and show the result. Nothing is saved to positions. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Position::class);

        $tooLarge = __('positions.import.errors.tooLarge', ['max' => $this->formatMegabytes($this->maxFileKilobytes())]);

        $request->validate([
            // `zip` because some servers report an Excel workbook as a plain
            // archive; the reader then refuses anything that is not a workbook.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,zip', 'max:'.$this->maxFileKilobytes()],
        ], [
            'file.required' => __('positions.import.errors.noFile'),
            'file.file' => __('positions.import.errors.noFile'),
            'file.uploaded' => $tooLarge,
            'file.mimes' => __('positions.import.errors.notCsv'),
            'file.max' => $tooLarge,
        ]);

        // Reading and checking 2,000 rows of a workbook can take longer than the default limit.
        @set_time_limit(120);

        try {
            $batch = $this->importService->validate($request->file('file'), Auth::user(), $request);
        } catch (PositionImportFileException $e) {
            // The file as a whole could not be read: say why beside the file field.
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $request->session()->put(self::SESSION_KEY, $batch->id);

        return to_route('positions.import.create')->with('flash', [
            'message' => __('positions.import.checked', [
                'valid' => $batch->valid_rows,
                'invalid' => $batch->failed_rows,
            ]),
            'type' => $batch->failed_rows > 0 || $batch->valid_rows === 0 ? 'warning' : 'success',
        ]);
    }

    /** Create the positions of a checked batch that passed. */
    public function confirm(Request $request, PositionImportBatch $batch): RedirectResponse
    {
        $this->authorize('create', Position::class);

        abort_unless($this->ownsBatch($batch), 403);

        // The batch stays in the session so the page can show what happened.
        $request->session()->put(self::SESSION_KEY, $batch->id);

        // A second click or a second tab arriving after the import ended: show the result, do not complain.
        if ($batch->isFinished()) {
            return to_route('positions.import.create');
        }

        if (! $batch->isImportable()) {
            return back()->with('flash', [
                'message' => __('positions.import.notImportable'),
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
                'message' => __('positions.import.failed'),
                'type' => 'error',
            ]);
        }

        // Rows still waiting: the page sends the next pass by itself, so no message yet.
        if ($result['remaining'] > 0) {
            return to_route('positions.import.create')->with(self::BETWEEN_PASSES_KEY, true);
        }

        return to_route('positions.import.create')->with('flash', [
            'message' => __('positions.import.imported', ['count' => $result['total_imported']]),
            'type' => $result['total_imported'] > 0 ? 'success' : 'warning',
        ]);
    }

    /** The rows that were left out, to correct and upload again. */
    public function errors(PositionImportBatch $batch): HttpResponse
    {
        $this->authorize('create', Position::class);

        abort_unless($this->ownsBatch($batch), 403);

        return response($this->importService->failedRowsCsv($batch), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="position-import-rows-to-fix.csv"',
        ]);
    }

    /** Discard the batch currently held in the session. */
    public function cancel(Request $request): RedirectResponse
    {
        $this->authorize('create', Position::class);

        $request->session()->forget(self::SESSION_KEY);

        return to_route('positions.import.create');
    }

    /** Download the template, as an Excel workbook or as CSV. */
    public function template(Request $request): HttpResponse
    {
        $this->authorize('create', Position::class);

        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'locale' => ['nullable', 'string', 'in:en,am'],
            'format' => ['nullable', 'string', 'in:csv,xlsx'],
            // Optional: only this unit and the units below it.
            'organization_unit_id' => ['nullable', 'uuid'],
        ]);

        $organization = $this->allowedOrganizations()->findOrFail($data['organization_id']);
        $locale = $data['locale'] ?? (in_array(app()->getLocale(), ['en', 'am'], true) ? app()->getLocale() : 'en');
        $extension = ($data['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $unit = $this->templateUnit($organization, $data['organization_unit_id'] ?? null);
        $disposition = $this->templateDisposition('position-import', $organization, $locale, $extension, $unit);

        if ($extension === 'xlsx') {
            return response($this->importService->templateXlsx($organization, $locale, $unit), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => $disposition,
            ]);
        }

        return response($this->importService->templateCsv($organization, $locale, $unit), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $disposition,
        ]);
    }

    /** The largest file that can arrive: this importer's cap or the server's own limit. */
    private function maxFileKilobytes(): int
    {
        return $this->maxUploadKilobytes(self::MAX_FILE_KILOBYTES);
    }

    /** A batch belongs to the actor when they uploaded it, or when they may see every organization anyway. */
    private function ownsBatch(PositionImportBatch $batch): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return (string) $batch->uploaded_by === (string) $user->getKey()
            || $this->scope->isUnrestricted($user);
    }

    /** @return array<string, mixed> */
    private function batchPayload(PositionImportBatch $batch): array
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
            'status' => $batch->status,
            'importable' => $batch->isImportable(),
            // True once the import ran to its last row, whether or not every row went in.
            'finished' => $batch->isFinished(),
            // True when an import started and rows still wait for the next pass.
            'in_progress' => $batch->isInProgress(),
        ];
    }

    /**
     * Active organizations the actor may import into.
     *
     * @return Builder<Organization>
     */
    private function allowedOrganizations(): Builder
    {
        $user = Auth::user();

        return Organization::query()
            ->where('status', 'active')
            ->when(
                ! $this->scope->isUnrestricted($user),
                fn ($query) => $query->whereIn('id', $this->scope->accessibleOrganizationIds($user)->all()),
            )
            ->orderBy('code');
    }
}
