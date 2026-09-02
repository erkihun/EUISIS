<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Exceptions\InvalidStructureWorkbookException;
use App\Exceptions\StructureImportCodeConflictException;
use App\Exports\Organizations\OrganizationStructureTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmOrganizationStructureImportRequest;
use App\Http\Requests\PreviewOrganizationStructureImportRequest;
use App\Http\Resources\OrganizationStructureImportPreviewResource;
use App\Models\Organization;
use App\Services\OrganizationStructureImport\OrganizationStructureImportService;
use App\Services\OrganizationStructureImport\StructureSheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The Organization Structure Import wizard: upload → preview → confirm.
 *
 * The preview and confirm steps both take the *file* (never a cached preview
 * id), and confirm re-validates from scratch before writing. That is what makes
 * "no import without a clean preview" enforceable server-side rather than a
 * UI convention.
 */
class OrganizationStructureImportController extends Controller
{
    public function __construct(
        private readonly OrganizationStructureImportService $importService,
    ) {}

    /**
     * The wizard page. This is the only GET URL in the flow: the preview and
     * confirm POSTs redirect back here rather than rendering in place, so the
     * address bar never holds a POST-only URL that a refresh would turn into a
     * 405.
     */
    public function create(Request $request): Response
    {
        $this->authorize('import', Organization::class);

        // Flashed by preview()/confirm() on the redirect back here.
        $preview = $request->session()->get('structure_import_preview');

        return Inertia::render('Organizations/ImportStructure', [
            'sheets' => $this->sheetDefinitions(),
            'preview' => $preview !== null
                ? new OrganizationStructureImportPreviewResource($preview)
                : null,
        ]);
    }

    /** Validate the workbook and render the preview. Writes nothing. */
    public function preview(PreviewOrganizationStructureImportRequest $request): RedirectResponse
    {
        $this->authorize('import', Organization::class);

        try {
            $preview = $this->importService->preview(
                $request->file('file'),
                $request->user(),
                (bool) $request->validated('auto_generate_codes'),
            );

            $randomCodeLocks = collect($preview['codes'] ?? [])
                ->filter(static fn (array $assignment): bool => (bool) ($assignment['uses_random_token'] ?? false))
                ->mapWithKeys(static fn (array $assignment): array => [
                    $assignment['sheet'].':'.$assignment['row'] => $assignment['generated_code'],
                ])
                ->filter()
                ->all();

            $request->session()->put('organization_structure_import_random_codes', [
                'file_fingerprint' => $this->importService->fingerprint($request->file('file')),
                'codes' => $randomCodeLocks,
            ]);
        } catch (InvalidStructureWorkbookException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return to_route('organizations.import-structure.create')
            ->with('structure_import_preview', $preview);
    }

    /**
     * Re-validate and import. A workbook that no longer validates cleanly comes
     * back as a preview with errors — never a partial import.
     */
    public function confirm(ConfirmOrganizationStructureImportRequest $request): RedirectResponse
    {
        $this->authorize('import', Organization::class);

        try {
            $randomCodeLock = (array) $request->session()->pull('organization_structure_import_random_codes', []);
            $lockedRandomCodes = hash_equals(
                (string) ($randomCodeLock['file_fingerprint'] ?? ''),
                $this->importService->fingerprint($request->file('file')),
            ) ? (array) ($randomCodeLock['codes'] ?? []) : [];

            $result = $this->importService->confirm(
                $request->file('file'),
                $request->user(),
                (bool) $request->validated('import_employees'),
                (bool) $request->validated('auto_generate_codes'),
                $lockedRandomCodes,
            );
        } catch (InvalidStructureWorkbookException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        } catch (StructureImportCodeConflictException $exception) {
            // A code shifted between preview and confirm — the transaction has
            // rolled back. Report the conflict verbatim so the user knows to
            // preview again rather than wondering what was written.
            return back()->withErrors(['file' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            // The import transaction has already rolled back; the audit trail
            // carries the reason. Surface a generic failure to the user.
            Log::error('Organization structure import failed.', [
                'user_id' => $request->user()?->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return back()->withErrors([
                'file' => __('organization-structure-import.errors.import_failed'),
            ]);
        }

        // Validation failed on re-check — send the errors back to the wizard
        // instead of importing.
        if (($result['can_import'] ?? false) === false) {
            return to_route('organizations.import-structure.create')
                ->with('structure_import_preview', $result);
        }

        return to_route('organizations.show', $result['result']['organization_id'])
            ->with('flash', [
                'message' => __('organization-structure-import.imported_successfully'),
                'type' => 'success',
            ]);
    }

    /** The blank workbook, with one sheet per structure sheet. */
    public function template(): BinaryFileResponse
    {
        $this->authorize('import', Organization::class);

        return Excel::download(
            new OrganizationStructureTemplateExport,
            'organization-structure-template.xlsx',
        );
    }

    /** @return list<array<string, mixed>> */
    private function sheetDefinitions(): array
    {
        return array_map(
            static fn (StructureSheet $sheet): array => [
                'name' => $sheet->value,
                'required' => $sheet->isRequired(),
                'columns' => $sheet->knownColumns(),
                'required_columns' => $sheet->requiredColumns(),
            ],
            StructureSheet::cases(),
        );
    }
}
