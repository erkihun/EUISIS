<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Data\ReportDocument;
use Modules\Reporting\Application\Ports\DocumentRenderer;
use Modules\Reporting\Application\Queries\WorkQueues;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\UseCases\PrepareReport;
use Modules\Reporting\Application\UseCases\SubmitReport;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\SubmissionMode;
use Modules\Reporting\Http\Requests\SaveDraftRequest;
use Modules\Reporting\Http\Requests\UploadFileRequest;
use Modules\Reporting\Http\Support\Present;
use Modules\Reporting\Infrastructure\Documents\ReportDocumentFactory;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Comment;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateSection;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateVersion;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** My assignments, and preparing and submitting one report in either mode. */
final class PrepareController extends ReportingController
{
    public function assignments(Request $request, WorkQueues $queues): Response
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);
        $view = in_array($request->query('view'), ['waiting', 'done'], true) ? $request->query('view') : 'open';
        $page = $queues->assignments($actor, $view)->paginate(20)->withQueryString();

        return Inertia::render('modules/reporting/Assignments', [
            'view' => $view,
            'rows' => $page->through(fn (Obligation $o): array => Present::obligation($o)),
            'can' => ['prepare' => $actor->can(ReportingRoles::PREPARE), 'submit' => $actor->can(ReportingRoles::SUBMIT)],
        ]);
    }

    public function edit(Request $request, string $obligation, PrepareReport $prepare, ReportingSettings $settings): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $record = Obligation::query()->with('cycle.reportType')->findOrFail($obligation);
        ReportingGuard::requireReportingSide($actor, $record, ReportingRoles::PREPARE);
        // Once submitted there is nothing to edit: the report's own page shows the version and its review.
        if (! $record->workflow_status->isEditable()) {
            return redirect()->route('reporting.obligations.show', $record->id);
        }
        $type = $record->cycle->reportType;
        $version = TemplateVersion::query()->findOrFail($record->template_version_id);
        $draft = $prepare->draft($record);

        return Inertia::render('modules/reporting/Prepare', [
            'obligation' => Present::obligation($record),
            'type' => [
                'instructions_en' => $type->instructions_en, 'instructions_am' => $type->instructions_am, 'allow_nil_report' => $type->allow_nil_report,
                'modes' => array_map(fn (SubmissionMode $m): string => $m->value, SubmissionMode::acceptedBy($type->submission_mode)),
                'late_policy' => $record->cycle->late_policy,
            ],
            'sections' => TemplateSection::query()->where('template_version_id', $version->id)->orderBy('position')->get()
                ->map->only(['key', 'title_en', 'title_am', 'guidance_en', 'guidance_am', 'is_required'])->all(),
            'required_attachments' => $version->required_attachments ?? [],
            'draft' => [
                'version_no' => $draft->version_no, 'mode' => $draft->mode, 'content' => (object) ($draft->content ?? []), 'summary' => $draft->summary,
                'is_nil' => $draft->is_nil, 'nil_reason' => $draft->nil_reason, 'completeness_declared' => $draft->completeness_declared,
                'lock_version' => $draft->lock_version, 'updated_at' => $draft->updated_at?->toIso8601String(),
            ],
            'attachments' => Attachment::query()->where('obligation_id', $record->id)->where('version_no', $record->current_version)->orderBy('created_at')->get()
                ->map(fn (Attachment $a): array => Present::attachment($a))->all(),
            // What the reviewer said when returning the previous version; internal notes are never sent to this page.
            'feedback' => Comment::query()->where('obligation_id', $record->id)->where('visibility', 'submitter')->orderByDesc('created_at')->limit(30)->get()
                ->map(fn (Comment $c): array => Present::comment($c))->all(),
            'can' => ['submit' => $actor->can(ReportingRoles::SUBMIT)],
            'max_upload_kb' => (int) $settings->get('max_upload_kb'),
        ]);
    }

    /** Autosave. Answers JSON with the new lock version; 409 when someone else saved first. */
    public function save(SaveDraftRequest $request, string $obligation, PrepareReport $prepare): JsonResponse
    {
        $draft = $prepare->save($this->actor($request), $obligation, $request->draft(), $request->integer('lock_version'));

        return response()->json(['lock_version' => $draft->lock_version, 'saved_at' => $draft->updated_at?->toIso8601String()]);
    }

    public function upload(UploadFileRequest $request, string $obligation, PrepareReport $prepare): JsonResponse
    {
        $file = $request->file('file');
        $attachment = $prepare->attach($this->actor($request), $obligation, $file->getRealPath(), $file->getClientOriginalName(), (string) $request->input('kind'), $request->input('requirement_key'));

        return response()->json(['attachment' => Present::attachment($attachment)], 201);
    }

    public function remove(Request $request, string $attachment, PrepareReport $prepare): JsonResponse
    {
        $prepare->removeAttachment($this->actor($request), $attachment);

        return response()->json(['removed' => true]);
    }

    public function submit(Request $request, string $obligation, SubmitReport $submit): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'late_reason' => ['nullable', 'string', 'max:2000']]);
        $submission = $submit->submit($this->actor($request), $obligation, (int) $data['lock_version'], $data['late_reason'] ?? null);

        return redirect()->route('reporting.obligations.show', $obligation)->with('success', __('reporting::messages.flash.submitted', ['reference' => $submission->reference_no]));
    }

    /** The empty Word template for this report, already carrying the institution and period. */
    public function template(Request $request, string $obligation, ReportDocumentFactory $documents, DocumentRenderer $renderer): BinaryFileResponse
    {
        $record = Obligation::query()->findOrFail($obligation);
        ReportingGuard::requireView($this->actor($request), $record);
        $document = $documents->forTemplate(TemplateVersion::query()->findOrFail($record->template_version_id), ReportType::query()->findOrFail($record->report_type_id), $this->language(), $record, Cycle::query()->findOrFail($record->cycle_id));

        return $this->word($renderer, $document, 'report-template');
    }

    /** The current draft as a Word file, labelled DRAFT. Generated on demand; it is not a submission. */
    public function draftDocument(Request $request, string $obligation, PrepareReport $prepare, ReportDocumentFactory $documents, DocumentRenderer $renderer): BinaryFileResponse
    {
        $record = Obligation::query()->findOrFail($obligation);
        ReportingGuard::requireReportingSide($this->actor($request), $record, ReportingRoles::PREPARE);

        return $this->word($renderer, $documents->forDraft($prepare->draft($record), $this->language()), 'report-draft');
    }

    private function word(DocumentRenderer $renderer, ReportDocument $document, string $name): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'rptd').'.docx';
        $renderer->renderWord($document, $path);

        return response()->download($path, "{$name}.docx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend();
    }
}
