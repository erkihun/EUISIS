<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\AssignSupportOfficer;
use Modules\ServiceMonitoring\Application\Actions\RegisterParticipant;
use Modules\ServiceMonitoring\Application\Actions\UpdateParticipant;
use Modules\ServiceMonitoring\Application\Services\ParticipantSuggester;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Http\Requests\ApplySuggestionsRequest;
use Modules\ServiceMonitoring\Http\Requests\AssignSupportRequest;
use Modules\ServiceMonitoring\Http\Requests\SaveParticipantRequest;

/** The participant registry (D7): CRUD, support officers and suggestions from the structure. */
final class ParticipantController extends Controller
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function index(Request $request, ParticipantSuggester $suggester): Response
    {
        $this->authorize('viewAny', Participant::class);
        $user = $request->user();
        $manage = $this->access->canManageParticipants($user);
        $level = $request->string('level')->toString();
        $search = trim($request->string('q')->toString());

        $participants = $this->access->scopeParticipants(Participant::query(), $user)
            ->with(['organization:id,name_en,name_am', 'parent.organization:id,name_en,name_am', 'supportOfficers:id,name'])
            ->when(in_array($level, ReportingLevel::values(), true), fn ($q) => $q->where('level', $level))
            ->when($search !== '', fn ($q) => $q->whereHas('organization', fn ($o) => $o->whereLike('name_en', "%{$search}%", caseSensitive: false)->orWhereLike('name_am', "%{$search}%", caseSensitive: false)))
            ->orderBy('level')
            ->paginate(50)->withQueryString();

        return Inertia::render('ServiceMonitoring/Participants/Index', [
            'participants' => $participants->through(fn (Participant $p): array => [
                'id' => $p->id,
                'level' => $p->level->value,
                'organization' => ['id' => $p->organization_id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am],
                'parent_participant_id' => $p->parent_participant_id,
                'parent' => $p->parent ? ['name_en' => $p->parent->organization?->name_en, 'name_am' => $p->parent->organization?->name_am] : null,
                'active_from' => $p->active_from->toDateString(),
                'active_to' => $p->active_to?->toDateString(),
                'support_officers' => $p->supportOfficers->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            ]),
            'filters' => ['level' => $level, 'q' => $search],
            'sub_cities' => Participant::query()->where('level', ReportingLevel::SubCity->value)->whereNull('active_to')->with('organization:id,name_en,name_am')->get()
                ->map(fn (Participant $p): array => ['id' => $p->id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am])->all(),
            'organizations' => $manage ? Organization::query()->where('status', 'active')
                ->whereNotIn('id', Participant::query()->whereNull('active_to')->select('organization_id'))
                ->orderBy('name_en')->limit(1000)->get(['id', 'name_en', 'name_am'])
                ->map(fn (Organization $o): array => ['id' => $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->all() : [],
            'support_candidates' => $manage ? User::query()->where('status', 'active')
                ->whereHas('roles.permissions', fn ($q) => $q->where('name', 'service_monitoring.support.respond'))
                ->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all() : [],
            'suggestions' => $manage ? Inertia::defer(fn (): array => $suggester->proposals(), 'suggestions') : null,
            'can' => ['manage' => $manage],
        ]);
    }

    public function store(SaveParticipantRequest $request, RegisterParticipant $register): RedirectResponse
    {
        $register->execute($request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.participant_saved'));
    }

    public function update(SaveParticipantRequest $request, Participant $participant, UpdateParticipant $update): RedirectResponse
    {
        $update->execute($participant, $request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.participant_saved'));
    }

    public function support(AssignSupportRequest $request, Participant $participant, AssignSupportOfficer $assign): RedirectResponse
    {
        $assign->execute($participant, array_map('intval', (array) $request->validated('user_ids')), $request->user());

        return back()->with('success', __('service-monitoring.flash.support_assigned'));
    }

    public function applySuggestions(ApplySuggestionsRequest $request, ParticipantSuggester $suggester): RedirectResponse
    {
        $wanted = array_flip((array) $request->validated('organization_ids'));
        $proposals = array_values(array_filter($suggester->proposals(), fn (array $p): bool => isset($wanted[$p['organization_id']])));
        $count = $suggester->apply($proposals, (string) $request->validated('active_from'), $request->user());

        return back()->with('success', __('service-monitoring.flash.suggestions_applied', ['count' => $count]));
    }
}
