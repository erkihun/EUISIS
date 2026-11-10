<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;

/** Counts and handling times for the institutions in scope. Counts only; no letter text leaves the registry here. */
final class ReportController extends Controller
{
    public function __invoke(Request $request, LettersAccess $access, LetterLookups $lookups): Response
    {
        abort_unless($request->user()->can('letters.reports.view'), 403);
        $user = $request->user();
        $from = (string) $request->string('from', now()->subDays(90)->toDateString());
        $to = (string) $request->string('to', now()->toDateString());
        $organizationId = (string) $request->string('organization_id');

        $base = fn (): Builder => $access->scopeRegistrations(LetterRegistration::query(), $user)
            ->whereNotNull('registered_at')->whereDate('registered_at', '>=', $from)->whereDate('registered_at', '<=', $to)
            ->when($organizationId !== '', fn ($q) => $q->where('organization_id', $organizationId));

        $byDirection = $base()->selectRaw('direction, count(*) as total')->groupBy('direction')->pluck('total', 'direction')->all();
        $byStatus = $base()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $overdue = $base()->whereIn('status', [RegistrationStatus::Registered->value, RegistrationStatus::Routed->value, RegistrationStatus::InProgress->value])
            ->whereNotNull('due_on')->where('due_on', '<', now()->toDateString())->count();

        $closed = $base()->where('status', RegistrationStatus::Closed->value)->whereNotNull('closed_at')->get(['registered_at', 'closed_at', 'due_on']);
        $avgDays = $closed->isEmpty() ? null : round($closed->avg(fn (LetterRegistration $r): float => $r->registered_at->diffInHours($r->closed_at) / 24), 1);
        // Reply-time standard: the share of letters closed by their due date (docs/letters-research.md §6).
        $withDue = $closed->filter(fn (LetterRegistration $r): bool => $r->due_on !== null);
        $onTimeRate = $withDue->isEmpty() ? null : (int) round(100 * $withDue->filter(fn (LetterRegistration $r): bool => $r->closed_at->startOfDay()->lte($r->due_on->endOfDay()))->count() / $withDue->count());
        $escalated = $base()->whereNotNull('escalated_at')->count();

        // Enum casts: compare on ->value, never with the collection's loose where().
        $direction = fn ($rows, string $value): int => $rows->filter(fn (LetterRegistration $r): bool => $r->direction->value === $value)->count();
        $status = fn ($rows, array $values): int => $rows->filter(fn (LetterRegistration $r): bool => in_array($r->status->value, $values, true))->count();

        $byInstitution = $base()->with('organization:id,code,name_en,name_am')->get(['id', 'organization_id', 'direction', 'status'])
            ->groupBy('organization_id')->map(fn ($rows): array => [
                'organization' => ['code' => $rows->first()->organization?->code, 'name_en' => $rows->first()->organization?->name_en, 'name_am' => $rows->first()->organization?->name_am],
                'incoming' => $direction($rows, 'incoming'), 'outgoing' => $direction($rows, 'outgoing'), 'internal' => $direction($rows, 'internal'),
                'open' => $status($rows, ['registered', 'routed', 'in_progress']), 'closed' => $status($rows, ['closed']),
            ])->sortByDesc(fn (array $r): int => $r['incoming'] + $r['outgoing'] + $r['internal'])->values()->all();

        $byMonth = $base()->get(['registered_at', 'direction'])->groupBy(fn (LetterRegistration $r): string => $r->registered_at->format('Y-m'))
            ->map(fn ($rows, string $month): array => ['month' => $month, 'incoming' => $direction($rows, 'incoming'), 'outgoing' => $direction($rows, 'outgoing'), 'internal' => $direction($rows, 'internal')])
            ->sortKeys()->values()->all();

        $routingOpen = LetterRouting::query()->whereHas('registration', fn (Builder $r) => $access->scopeRegistrations($r, $user)->when($organizationId !== '', fn ($q) => $q->where('organization_id', $organizationId)))
            ->whereIn('status', ['pending', 'acknowledged'])->with('toUnit:id,code,name_en,name_am')->get(['id', 'to_unit_id', 'due_on', 'status'])
            ->groupBy(fn (LetterRouting $r): string => $r->to_unit_id ?? '—')->map(fn ($rows): array => [
                'unit' => $rows->first()->toUnit !== null ? ['code' => $rows->first()->toUnit->code, 'name_en' => $rows->first()->toUnit->name_en, 'name_am' => $rows->first()->toUnit->name_am] : null,
                'open' => $rows->count(), 'overdue' => $rows->filter(fn (LetterRouting $r): bool => $r->due_on !== null && $r->due_on->lt(now()->startOfDay()))->count(),
            ])->sortByDesc('open')->values()->all();

        return Inertia::render('Letters/Reports', [
            'filters' => ['from' => $from, 'to' => $to, 'organization_id' => $organizationId],
            'organizations' => $lookups->actingOrganizations($user, 'letters.reports.view'),
            'totals' => ['by_direction' => $byDirection, 'by_status' => $byStatus, 'overdue' => $overdue, 'avg_days_to_close' => $avgDays, 'closed' => $closed->count(), 'on_time_rate' => $onTimeRate, 'escalated' => $escalated],
            'by_institution' => $byInstitution,
            'by_month' => $byMonth,
            'routing_by_unit' => $routingOpen,
        ]);
    }
}
