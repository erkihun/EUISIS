<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\CloseClarification;
use Modules\ServiceMonitoring\Application\Actions\OpenClarification;
use Modules\ServiceMonitoring\Application\Actions\ReplyClarification;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\ClarificationStatus;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Http\Requests\OpenClarificationRequest;
use Modules\ServiceMonitoring\Http\Requests\ReplyClarificationRequest;

/** Clarification threads between institutions and their support officers (D22). */
final class ClarificationController extends Controller
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function index(Request $request, ParticipantResolver $participants): Response
    {
        $this->authorize('viewAny', Participant::class);
        $user = $request->user();
        $status = ClarificationStatus::tryFrom($request->string('status')->toString());

        $threads = Clarification::query()
            ->whereHas('participant', fn ($q) => $this->access->scopeParticipants($q, $user))
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->with(['participant.organization:id,name_en,name_am', 'opener:id,name'])->withCount('messages')
            ->latest('updated_at')->paginate(30)->withQueryString();

        return Inertia::render('ServiceMonitoring/Clarifications/Index', [
            'threads' => $threads->through(fn (Clarification $c): array => [
                'id' => $c->id, 'subject' => $c->subject, 'status' => $c->status->value, 'section' => $c->section, 'messages' => $c->messages_count,
                'opened_by' => $c->opener?->name, 'updated_at' => $c->updated_at?->toIso8601String(),
                'organization' => ['name_en' => $c->participant?->organization?->name_en, 'name_am' => $c->participant?->organization?->name_am],
            ]),
            'filters' => ['status' => $status?->value],
            'own_participants' => $participants->reportingFor($user, now()->toDateString())
                ->filter(fn (Participant $p): bool => $this->access->canOpenClarification($user, $p))
                ->map(fn (Participant $p): array => ['id' => $p->id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am])->values()->all(),
        ]);
    }

    public function store(OpenClarificationRequest $request, OpenClarification $open): RedirectResponse
    {
        $participant = Participant::query()->findOrFail($request->validated('participant_id'));
        $clarification = $open->execute($participant, $request->validated(), $request->user());

        return redirect()->route('service-monitoring.clarifications.show', $clarification)->with('success', __('service-monitoring.flash.clarification_opened'));
    }

    public function show(Request $request, Clarification $clarification): Response
    {
        $this->authorize('view', $clarification);
        $clarification->load(['participant.organization:id,name_en,name_am', 'messages.author:id,name', 'period.fiscalYear']);
        $user = $request->user();

        return Inertia::render('ServiceMonitoring/Clarifications/Show', [
            'clarification' => [
                'id' => $clarification->id, 'subject' => $clarification->subject, 'status' => $clarification->status->value, 'section' => $clarification->section,
                'submission_id' => $clarification->submission_id, 'period_label' => $clarification->period?->label(),
                'organization' => ['name_en' => $clarification->participant?->organization?->name_en, 'name_am' => $clarification->participant?->organization?->name_am],
                'messages' => $clarification->messages->map(fn ($m): array => [
                    'id' => $m->id, 'body' => $m->body, 'author' => $m->author?->name, 'mine' => $m->author_id === $user->id, 'created_at' => $m->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'can' => [
                'reply' => $user->can('reply', $clarification) && $clarification->status !== ClarificationStatus::Closed,
                'close' => $user->can('close', $clarification) && $clarification->status !== ClarificationStatus::Closed,
            ],
        ]);
    }

    public function reply(ReplyClarificationRequest $request, Clarification $clarification, ReplyClarification $reply): RedirectResponse
    {
        $reply->execute($clarification, (string) $request->validated('body'), $request->user(), $this->access->canRespond($request->user(), $clarification));

        return back()->with('success', __('service-monitoring.flash.clarification_replied'));
    }

    public function close(Request $request, Clarification $clarification, CloseClarification $close): RedirectResponse
    {
        $this->authorize('close', $clarification);
        $close->execute($clarification, $request->user());

        return back()->with('success', __('service-monitoring.flash.clarification_closed'));
    }
}
