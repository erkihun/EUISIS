<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SituationRoom;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Exports\SituationRoom\SituationRoomSheetExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\SituationRoom\ExportSituationRoomRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationExportService;
use App\Services\SituationRoom\SituationLabels;
use App\Services\SituationRoom\SituationRoomService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Executive Reports: a category the viewer may open, exported as CSV, Excel
 * or PDF, in the viewer's scope and filters. Every export is audited with
 * its category, format and filters (never its content).
 */
final class SituationReportController extends Controller
{
    public function __construct(
        private readonly SituationRoomService $room,
        private readonly SituationExportService $exports,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->room->context($user, $request);

        return Inertia::render('SituationRoom/Reports', [
            'labels' => SituationLabels::dictionary(),
            'categories' => array_map(fn (SituationCategory $c): array => $c->toArray(), SituationCategory::visibleFor($user)),
            'filters' => $context->filters->toArray(),
            'query' => $context->filters->query(),
            'formats' => SituationExportService::FORMATS,
        ]);
    }

    public function export(ExportSituationRoomRequest $request): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        $category = $request->category();
        $format = (string) $request->validated('format');
        $locale = (string) ($request->validated('locale') ?? app()->getLocale());
        $context = $this->room->context($user, $request);
        // Exports never carry names: the employee-level table stays on screen, behind its own grant and audit.
        $result = $this->room->run($this->room->analytics($category), $context, fn (string $key): bool => ! str_ends_with($key, SituationRoomService::DETAIL_PANEL_SUFFIX));
        $entry = ['generated_at' => now()->toIso8601String(), 'payload' => $result];
        $document = $this->exports->document($category, $context, $entry, $this->scopeLabel($context->scope->focusOrganizationId, $context->scope->unrestricted, $locale), $locale);

        $this->audit->execute(
            eventType: AuditEventType::SituationRoomExported,
            actor: $user,
            organizationId: $context->scope->focusOrganizationId,
            newValues: ['category' => $category->value, 'format' => $format, 'locale' => $locale, 'filters' => $context->filters->toArray()],
            request: $request,
        );

        $filename = 'situation-room-'.$category->value.'-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            return Pdf::loadView('situation-room.export', [
                'document' => $document,
                'locale' => $locale,
                'heading' => SituationLabels::text('title', $locale),
                'labels' => [
                    'scope' => SituationLabels::text('export.scope', $locale),
                    'period' => SituationLabels::text('export.period', $locale),
                    'generated' => SituationLabels::text('export.generated', $locale),
                    'footer' => SituationLabels::text('export.footer', $locale),
                ],
            ])->setPaper('a4', 'portrait')->download($filename.'.pdf');
        }

        $sheet = $this->exports->sheet($document, $locale);

        return Excel::download(
            new SituationRoomSheetExport($sheet['headings'], $sheet['rows'], $format === 'csv'),
            $filename.'.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }

    private function scopeLabel(?string $focus, bool $unrestricted, string $locale): string
    {
        if ($focus !== null) {
            $organization = Organization::query()->find($focus, ['id', 'name_en', 'name_am']);

            return SituationLabels::resolve(['en' => $organization?->name_en, 'am' => $organization?->name_am], $locale);
        }

        return SituationLabels::text($unrestricted ? 'scope.all' : 'scope.mine', $locale);
    }
}
