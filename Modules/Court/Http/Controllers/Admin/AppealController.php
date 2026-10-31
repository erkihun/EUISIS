<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Application\Services\AppealRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class AppealController extends Controller
{
    public function index(Request $request)
    {
        $appeals = DB::table('court_appeals as a')
            ->join('court_cases as c', 'c.id', '=', 'a.court_case_id')
            ->leftJoin('court_staff as u', 'u.id', '=', 'a.decided_by_user_id')
            ->select('a.*', 'c.case_number', 'u.name as decided_by')
            ->when($request->filled('status'), fn($q) => $q->where('a.status', $request->status))
            ->orderByDesc('a.created_at')
            ->paginate(15)
            ->withQueryString();

        // The register (React, Court/Appeals/Index).
        return Inertia::render('Court/Appeals/Index', [
            'appeals' => $appeals->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'appeal_number' => (string) $row->appeal_number,
                'case_number' => (string) $row->case_number,
                'title' => $row->title,
                'status' => (string) $row->status,
                'decided_by' => $row->decided_by,
                'created_at' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ]),
            'filters' => ['status' => (string) $request->query('status', '')],
            'canCreate' => (bool) ($request->user()?->hasPermission('appeals.create') ?? false),
        ]);
    }

    /** The registry records a party's request to appeal (EUISIS ADR 0013 #14): only closed cases with a published decision. */
    public function create(Request $request, AppealRecords $appeals)
    {
        $cases = DB::table('court_cases')
            ->whereIn('status', ['closed', 'dismissed'])->whereNull('merged_into_case_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('court_decisions as d')->whereColumn('d.court_case_id', 'court_cases.id')->where('d.status', 'published')->whereNotNull('d.approved_at'))
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'case_number', 'title', 'respondent_name']);

        return Inertia::render('Court/Appeals/Form', [
            'appeal' => null,
            'cases' => $cases,
            'caseId' => $request->integer('case') ?: null,
            'sides' => AppealRecords::SIDES,
        ]);
    }

    public function store(Request $request, AppealRecords $appeals)
    {
        $data = $request->validate([
            'court_case_id' => ['required', 'integer', 'exists:court_cases,id'],
            'appellant_type' => ['required', 'in:'.implode(',', AppealRecords::SIDES)],
            'appellant_name' => ['required', 'string', 'max:255'],
            'grounds' => ['required', 'string', 'max:10000'],
        ]);

        $appeal = $appeals->request((int) $data['court_case_id'], $data['appellant_type'], trim($data['appellant_name']), trim($data['grounds']), 'registry', by: $request->user());

        return redirect()->route('court.appeals.show', $appeal->id)->with('success', __('court::appeal_records.flash.requested'));
    }

    public function show($appealId)
    {
        $appeal = DB::table('court_appeals as a')
            ->join('court_cases as c', 'c.id', '=', 'a.court_case_id')
            ->leftJoin('court_staff as du', 'du.id', '=', 'a.decided_by_user_id')
            ->select('a.*', 'c.case_number', 'c.title as case_title', 'du.name as decided_by')
            ->where('a.id', $appealId)
            ->first();

        abort_if(!$appeal, 404);

        $docs = DB::table('court_appeal_documents')
            ->where('appeal_id', $appealId)
            ->orderByDesc('created_at')
            ->get();

        $user = auth()->user();
        $can = fn (string $permission): bool => (bool) ($user?->hasPermission($permission) ?? false);
        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;

        return Inertia::render('Court/Appeals/Show', [
            'appeal' => [
                'id' => (int) $appeal->id,
                'appeal_number' => (string) $appeal->appeal_number,
                'title' => (string) $appeal->title,
                'grounds' => $appeal->grounds,
                'status' => (string) $appeal->status,
                'case_id' => (int) $appeal->court_case_id,
                'case_number' => (string) $appeal->case_number,
                'case_title' => $appeal->case_title,
                'decided_by' => $appeal->decided_by,
                'decision_notes' => $appeal->decision_notes ?? null,
                'submitted_at' => $iso($appeal->submitted_at ?? null),
                'decided_at' => $iso($appeal->decided_at ?? null),
                'created_at' => $iso($appeal->created_at),
                // EUISIS ADR 0013 #14: who appeals, how the request came, the release and the appellate outcome.
                'side' => $appeal->appellant_type ?? null,
                'appellant' => $appeal->appellant_name ?? null,
                'via' => $appeal->requested_via ?? null,
                'released_at' => $iso($appeal->released_at ?? null),
                'outcome' => $appeal->outcome ?? null,
                'outcome_date' => $appeal->outcome_date ?? null,
                'appellate_court' => $appeal->appellate_court ?? null,
                'appellate_case_number' => $appeal->appellate_case_number ?? null,
                'outcome_notes' => $appeal->outcome_notes ?? null,
                'outcome_document_url' => ! empty($appeal->outcome_document_path) ? route('court.appeals.outcome-document', $appeal->id) : null,
            ],
            'documents' => $docs->map(fn (object $doc): array => ['id' => (int) $doc->id, 'label' => $doc->label, 'created_at' => $iso($doc->created_at)])->values()->all(),
            'can' => ['edit' => $can('appeals.edit'), 'decide' => $can('appeals.decide'), 'upload' => $can('appeals.edit'), 'outcome' => $can('appeals.decide')],
            'outcomes' => AppealRecords::OUTCOMES,
        ]);
    }

    public function edit($appealId)
    {
        $appeal = DB::table('court_appeals')->where('id', $appealId)->first();
        abort_if(!$appeal, 404);

        if (!in_array($appeal->status, ['draft', 'submitted'], true)) {
            return redirect()->route('court.appeals.show', $appealId)
                ->with('error', 'court::messages.error.cannot_edit_stage');
        }

        return Inertia::render('Court/Appeals/Form', [
            'appeal' => ['id' => (int) $appeal->id, 'appeal_number' => (string) $appeal->appeal_number, 'title' => (string) $appeal->title, 'grounds' => $appeal->grounds],
            'cases' => [],
        ]);
    }

    public function update(Request $request, $appealId)
    {
        $appeal = DB::table('court_appeals')->where('id', $appealId)->first();
        abort_if(!$appeal, 404);

        // Same stage rule as edit(): a decided appeal is a record and must not change.
        if (!in_array($appeal->status, ['draft', 'submitted'], true)) {
            return redirect()->route('court.appeals.show', $appealId)
                ->with('error', 'court::messages.error.cannot_edit_stage');
        }

        $data = $request->validate([
            'title'   => ['required', 'string', 'max:255'],
            'grounds' => ['nullable', 'string', 'max:10000'],
        ]);

        DB::table('court_appeals')->where('id', $appealId)->update([
            'title'      => $data['title'],
            'grounds'    => $data['grounds'] ?? null,
            'updated_at' => now(),
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.appeal')],
        ]);
    }

    public function submit($appealId)
    {
        $appeal = DB::table('court_appeals')->where('id', $appealId)->first();
        abort_if(!$appeal, 404);

        if ($appeal->status !== 'draft') {
            return back()->with('error', 'court::messages.error.only_drafts_submit');
        }

        DB::table('court_appeals')->where('id', $appealId)->update([
            'status'       => 'submitted',
            'submitted_at' => now(),
            'updated_at'   => now(),
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.submitted',
            'replace' => ['resource' => __('court::messages.resources.appeal')],
        ]);
    }

    /**
     * The registry answers a request: approved releases the record to the party (the
     * case is then marked as appealed), rejected refuses it with the reason.
     */
    public function decide(Request $request, $appealId, AppealRecords $appeals)
    {
        $appeal = DB::table('court_appeals')->where('id', $appealId)->first();
        abort_if(!$appeal, 404);

        $data = $request->validate([
            'decision'        => ['required', 'in:approved,rejected,closed'],
            'decision_notes'  => ['nullable', 'string', 'max:5000'],
        ]);

        if (!in_array($appeal->status, ['submitted', 'under_review'], true)) {
            return back()->with('error', 'court::messages.error.not_decidable');
        }

        match ($data['decision']) {
            'approved' => $appeals->release($appeal, $request->user(), $data['decision_notes'] ?? null),
            'rejected' => $appeals->refuse($appeal, $request->user(), $data['decision_notes'] ?? null),
            default => DB::table('court_appeals')->where('id', $appealId)->update([
                'status' => 'closed', 'decided_by_user_id' => auth()->id(), 'decided_at' => now(),
                'decision_notes' => $data['decision_notes'] ?? null, 'updated_at' => now(),
            ]),
        };

        return back()->with('success', match ($data['decision']) {
            'approved' => __('court::appeal_records.flash.released'),
            'rejected' => __('court::appeal_records.flash.refused'),
            default => ['key' => 'court::messages.success.recorded', 'replace' => ['resource' => __('court::messages.resources.decision')]],
        });
    }

    /** What the appellate court decided on a released record; it scores the judges of the panel. */
    public function outcome(Request $request, $appealId, AppealRecords $appeals)
    {
        $appeal = DB::table('court_appeals')->where('id', $appealId)->first();
        abort_if(!$appeal, 404);

        $data = $request->validate([
            'outcome' => ['required', 'in:'.implode(',', AppealRecords::OUTCOMES)],
            'outcome_date' => ['required', 'date', 'before_or_equal:today'],
            'appellate_court' => ['nullable', 'string', 'max:255'],
            'appellate_case_number' => ['nullable', 'string', 'max:120'],
            'outcome_notes' => ['nullable', 'string', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);
        $path = $request->hasFile('document')
            ? app(\Modules\Court\Application\Services\SecureUploadService::class)->store($request->file('document'), 'appeals', 'court_private', ['related_type' => 'appeal', 'related_id' => $appeal->id, 'user_id' => auth()->id()])
            : null;
        $appeals->recordOutcome($appeal, $request->user(), $data, $path);

        return back()->with('success', __('court::appeal_records.flash.outcome'));
    }

    public function outcomeDocument($appealId)
    {
        $path = DB::table('court_appeals')->where('id', $appealId)->value('outcome_document_path');
        abort_if(! $path || ! Storage::disk('court_private')->exists($path), 404);

        return Storage::disk('court_private')->download($path, 'appellate-decision-'.$appealId.'.pdf');
    }

    public function uploadDoc(Request $request, $appealId)
    {
        $appeal = DB::table('court_appeals')->where('id', $appealId)->first();
        abort_if(!$appeal, 404);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'file'  => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:5120'], // 5MB
        ]);

        $file = $request->file('file');
        $path = app(\Modules\Court\Application\Services\SecureUploadService::class)->store($file, 'appeals', 'court_private', [
            'related_type' => 'appeal',
            'related_id' => $appeal->id,
            'user_id' => auth()->id(),
        ]);

        DB::table('court_appeal_documents')->insert([
            'appeal_id'  => $appealId,
            'label'      => $data['label'] ?? null,
            'path'       => $path,
            'mime'       => $file->getClientMimeType(),
            'size'       => $file->getSize(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.uploaded',
            'replace' => ['resource' => __('court::messages.resources.document')],
        ]);
    }

    public function deleteDoc($appealId, $docId)
    {
        $doc = DB::table('court_appeal_documents')
            ->where('id', $docId)
            ->where('appeal_id', $appealId)
            ->first();

        abort_if(!$doc, 404);

        if (!empty($doc->path)) {
            app(\Modules\Court\Application\Services\LegalHoldService::class)->assertFileMayBeDeleted((string) $doc->path);
            Storage::disk('court_private')->delete($doc->path);
            Storage::disk('court_public')->delete($doc->path); // legacy cleanup
        }

        DB::table('court_appeal_documents')->where('id', $docId)->delete();

        return back()->with('success', [
            'key' => 'court::messages.success.removed',
            'replace' => ['resource' => __('court::messages.resources.document')],
        ]);
    }
}
