<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\ReportContentAction;
use App\Actions\Communication\ResolveReportAction;
use App\Enums\Communication\ReportCategory;
use App\Enums\Communication\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreReportRequest;
use App\Models\Communication\CommunicationReport;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Services\Communication\ReportedContentViewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Members report content; moderators review it through the narrow ReportedContentViewer. */
class ModerationController extends Controller
{
    public function reportMessage(StoreReportRequest $request, Message $message, ReportContentAction $report): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $message), 404);
        $filed = $report->message($request->user(), $message, ReportCategory::from($request->validated('category')), $request->validated('details'));

        return response()->json(['id' => $filed->getKey()], 201);
    }

    public function reportConversation(StoreReportRequest $request, Conversation $conversation, ReportContentAction $report): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $conversation), 404);
        $filed = $report->conversation($request->user(), $conversation, ReportCategory::from($request->validated('category')), $request->validated('details'));

        return response()->json(['id' => $filed->getKey()], 201);
    }

    public function index(Request $request): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('viewAny', CommunicationReport::class), 403);

        return Inertia::render('Communication/Moderation', [
            'initial' => $this->queue($request, 'active'),
            'counts' => CommunicationReport::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('viewAny', CommunicationReport::class), 403);
        $status = in_array($request->query('status'), ['active', 'closed', 'all'], true) ? (string) $request->query('status') : 'active';

        return response()->json($this->queue($request, $status));
    }

    public function show(Request $request, CommunicationReport $report, ReportedContentViewer $viewer): JsonResponse
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('review', $report) && (int) $report->reported_user_id !== (int) $user->getKey(), 404);

        // Opening first: it moves the report to "under review", which the summary then shows.
        $content = $viewer->open($user, $report, app()->getLocale());

        return response()->json([
            'report' => $this->present($report->load(['reporter:id,name', 'reportedUser:id,name', 'reviewer:id,name'])),
            ...$content,
        ]);
    }

    public function resolve(Request $request, CommunicationReport $report, ResolveReportAction $resolve): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:ACTIONED,DISMISSED'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'remove_message' => ['boolean'],
        ]);
        abort_unless(Gate::forUser($request->user())->allows('review', $report), 404);
        $resolve->execute($request->user(), $report, ReportStatus::from($data['status']), $data['note'], (bool) ($data['remove_message'] ?? false));

        return response()->json(['report' => $this->present($report->fresh(['reporter:id,name', 'reportedUser:id,name', 'reviewer:id,name']))]);
    }

    /** @return array{data: list<array<string, mixed>>} */
    private function queue(Request $request, string $status): array
    {
        $user = $request->user();
        $query = CommunicationReport::query()->with(['reporter:id,name', 'reportedUser:id,name', 'reviewer:id,name'])
            // A moderator never sees reports they filed or reports about themselves in their queue.
            ->where('reporter_id', '<>', $user->getKey())
            ->where(fn ($q) => $q->whereNull('reported_user_id')->orWhere('reported_user_id', '<>', $user->getKey()));
        match ($status) {
            'active' => $query->whereIn('status', [ReportStatus::Open->value, ReportStatus::UnderReview->value])->orderBy('created_at'),
            'closed' => $query->whereIn('status', [ReportStatus::Actioned->value, ReportStatus::Dismissed->value])->orderByDesc('reviewed_at'),
            default => $query->orderByDesc('created_at'),
        };

        return ['data' => $query->limit(200)->get()->map(fn (CommunicationReport $r) => $this->present($r))->values()->all()];
    }

    /** @return array<string, mixed> */
    private function present(CommunicationReport $r): array
    {
        return [
            'id' => $r->getKey(),
            'subject' => $r->subject_type->value,
            'category' => $r->category->value,
            'details' => $r->details,
            'status' => $r->status->value,
            'reporter' => $r->reporter?->name,
            'reported_user' => $r->reportedUser?->name,
            'reviewer' => $r->reviewer?->name,
            'resolution_note' => $r->resolution_note,
            'created_at' => $r->created_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
