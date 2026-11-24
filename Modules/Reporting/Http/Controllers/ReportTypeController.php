<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Ports\DocumentRenderer;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\UseCases\ConfigureReportTypes;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Infrastructure\Documents\ReportDocumentFactory;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateSection;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateVersion;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Report types and their templates (sections, required attachments, review steps). */
final class ReportTypeController extends ReportingController
{
    public function index(Request $request): Response
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::MANAGE_TEMPLATES);
        $versions = TemplateVersion::query()->orderBy('version')->get()->groupBy('report_type_id');

        return Inertia::render('modules/reporting/Types', [
            'types' => ReportType::query()->orderBy('name_en')->get()->map(fn (ReportType $t): array => [
                ...$t->only(['id', 'code', 'name_en', 'name_am', 'frequency', 'submission_mode', 'confidentiality', 'is_active', 'allow_nil_report']),
                'published_version' => $versions->get($t->id)?->where('status', 'published')->max('version'),
                'has_draft' => (bool) $versions->get($t->id)?->contains('status', 'draft'),
            ])->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::MANAGE_TEMPLATES);

        return Inertia::render('modules/reporting/TypeForm', [
            'type' => null, 'receiving' => null, 'template' => null, 'published' => [],
            'defaults' => ['sections' => ConfigureReportTypes::DEFAULT_SECTIONS, 'steps' => ConfigureReportTypes::DEFAULT_WORKFLOW['steps']],
        ]);
    }

    public function edit(Request $request, string $type, OrganizationDirectory $organizations): Response
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::MANAGE_TEMPLATES);
        $record = ReportType::query()->findOrFail($type);
        $versions = TemplateVersion::query()->where('report_type_id', $record->id)->orderByDesc('version')->get();
        // The working copy: the unpublished version, or the latest published one as the starting point of the next.
        $working = $versions->firstWhere('status', 'draft') ?? $versions->first();

        return Inertia::render('modules/reporting/TypeForm', [
            'type' => $record->only(['id', 'code', 'name_en', 'name_am', 'instructions_en', 'instructions_am', 'frequency', 'receiving_organization_id', 'submission_mode', 'confidentiality', 'allow_nil_report', 'allow_self_approval', 'is_active']),
            'receiving' => $record->receiving_organization_id === null ? null : $organizations->find($record->receiving_organization_id)?->toArray(),
            'template' => $working === null ? null : [
                'version' => $working->version, 'status' => $working->status,
                'sections' => TemplateSection::query()->where('template_version_id', $working->id)->orderBy('position')->get()->map->only(['key', 'title_en', 'title_am', 'guidance_en', 'guidance_am', 'is_required'])->all(),
                'steps' => $working->workflow['steps'] ?? [], 'required_attachments' => $working->required_attachments ?? [],
            ],
            'published' => $versions->where('status', 'published')->map(fn (TemplateVersion $v): array => ['version' => $v->version, 'published_at' => $v->published_at?->toIso8601String()])->values()->all(),
            'defaults' => ['sections' => ConfigureReportTypes::DEFAULT_SECTIONS, 'steps' => ConfigureReportTypes::DEFAULT_WORKFLOW['steps']],
        ]);
    }

    public function store(Request $request, ConfigureReportTypes $configure): RedirectResponse
    {
        $type = $configure->saveType($this->actor($request), null, $this->validated($request, null));

        return redirect()->route('reporting.types.edit', $type->id)->with('success', __('reporting::messages.flash.saved'));
    }

    public function update(Request $request, string $type, ConfigureReportTypes $configure): RedirectResponse
    {
        $configure->saveType($this->actor($request), $type, $this->validated($request, $type));

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    public function saveTemplate(Request $request, string $type, ConfigureReportTypes $configure): RedirectResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1', 'max:40'],
            'sections.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'sections.*.title_en' => ['required', 'string', 'max:200'],
            'sections.*.title_am' => ['required', 'string', 'max:200'],
            'sections.*.guidance_en' => ['nullable', 'string', 'max:2000'],
            'sections.*.guidance_am' => ['nullable', 'string', 'max:2000'],
            'sections.*.is_required' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:6'],
            'steps.*.name_en' => ['required', 'string', 'max:200'],
            'steps.*.name_am' => ['required', 'string', 'max:200'],
            'steps.*.permission' => ['required', Rule::in([ReportingRoles::REVIEW, ReportingRoles::APPROVE])],
            'required_attachments' => ['array', 'max:20'],
            'required_attachments.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'required_attachments.*.label_en' => ['required', 'string', 'max:200'],
            'required_attachments.*.label_am' => ['required', 'string', 'max:200'],
        ]);
        $configure->saveTemplateDraft($this->actor($request), $type, $data['sections'], $data['steps'], $data['required_attachments'] ?? []);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    public function publishTemplate(Request $request, string $type, ConfigureReportTypes $configure): RedirectResponse
    {
        $version = $configure->publishTemplate($this->actor($request), $type);

        return back()->with('success', __('reporting::messages.flash.template_published', ['version' => $version->version]));
    }

    /** The published template as an empty Word file, for preparing a report offline. */
    public function templateDocument(Request $request, string $type, ConfigureReportTypes $configure, ReportDocumentFactory $documents, DocumentRenderer $renderer): BinaryFileResponse
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::VIEW);
        $record = ReportType::query()->findOrFail($type);
        $version = $configure->publishedVersion($record->id) ?? TemplateVersion::query()->where('report_type_id', $record->id)->orderByDesc('version')->firstOrFail();
        $path = tempnam(sys_get_temp_dir(), 'rptt').'.docx';
        $renderer->renderWord($documents->forTemplate($version, $record, $this->language()), $path);

        return response()->download($path, 'report-template.docx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?string $typeId): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_\-]*$/', Rule::unique('reporting_report_types', 'code')->ignore($typeId)],
            'name_en' => ['required', 'string', 'max:200'],
            'name_am' => ['required', 'string', 'max:200'],
            'instructions_en' => ['nullable', 'string', 'max:5000'],
            'instructions_am' => ['nullable', 'string', 'max:5000'],
            'frequency' => ['required', Rule::in(['weekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'ad_hoc'])],
            'receiving_organization_id' => ['nullable', 'uuid'],
            'submission_mode' => ['required', Rule::in(['structured', 'upload', 'either'])],
            'confidentiality' => ['required', Rule::in(['internal', 'confidential'])],
            'allow_nil_report' => ['boolean'],
            'allow_self_approval' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
