<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Services\LetterPresenter;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;

/** The letters desk: what waits for the institutions in scope and for the signed-in person. */
final class LettersDashboardController extends Controller
{
    public function __invoke(Request $request, LettersAccess $access, LetterPresenter $presenter): Response
    {
        $this->authorize('viewAny', Letter::class);
        $user = $request->user();
        $today = now()->toDateString();

        $registrations = fn () => $access->scopeRegistrations(LetterRegistration::query(), $user);
        $mine = LetterRouting::query()->where('to_user_id', $user->id)->whereIn('status', [RoutingStatus::Pending->value, RoutingStatus::Acknowledged->value]);

        $counts = [
            'awaiting_receipt' => (clone $registrations())->where('status', RegistrationStatus::PendingReceipt->value)->count(),
            'open' => (clone $registrations())->whereIn('status', [RegistrationStatus::Registered->value, RegistrationStatus::Routed->value, RegistrationStatus::InProgress->value])->count(),
            'overdue' => (clone $registrations())->whereIn('status', [RegistrationStatus::Registered->value, RegistrationStatus::Routed->value, RegistrationStatus::InProgress->value])->whereNotNull('due_on')->where('due_on', '<', $today)->count(),
            'registered_today' => (clone $registrations())->whereDate('registered_at', $today)->count(),
            'my_routings' => (clone $mine)->count(),
            'my_overdue' => (clone $mine)->whereNotNull('due_on')->where('due_on', '<', $today)->count(),
            'drafts' => $user->can('letters.view') ? $access->scopeLetters(Letter::query(), $user)->whereIn('status', [LetterStatus::Draft->value, LetterStatus::PendingApproval->value, LetterStatus::Approved->value])->count() : 0,
            'awaiting_signature' => $user->can('letters.approve') ? $access->scopeLetters(Letter::query(), $user)->where('status', LetterStatus::PendingApproval->value)->count() : 0,
        ];

        $recent = $registrations()->with(['letter.organization', 'letter.senderOrganization', 'letter.recipientOrganization', 'letter.unit', 'letter.routings', 'organization', 'routings'])
            ->whereNotNull('registered_at')->orderByDesc('registered_at')->limit(8)->get()
            ->map(fn (LetterRegistration $r): array => $presenter->row($r->letter, $r, $user))->values()->all();

        $myRoutings = (clone $mine)->with(['letter.organization', 'letter.senderOrganization', 'letter.recipientOrganization', 'registration.organization'])
            ->orderByRaw('due_on is null')->orderBy('due_on')->limit(8)->get()
            ->map(fn (LetterRouting $r): array => [
                'id' => $r->id, 'letter_id' => $r->letter_id, 'subject' => $r->letter?->subject, 'number' => $r->registration?->registry_number,
                'instruction' => $r->instruction, 'due_on' => $r->due_on?->toDateString(), 'status' => $r->status->value,
                'organization' => ['name_en' => $r->registration?->organization?->name_en, 'name_am' => $r->registration?->organization?->name_am],
            ])->values()->all();

        return Inertia::render('Letters/Dashboard', [
            'counts' => $counts,
            'recent' => $recent,
            'my_routings' => $myRoutings,
            'can' => [
                'register' => $user->can('letters.register'),
                'compose' => $user->can('letters.compose'),
                'templates' => $access->canViewTemplates($user),
                'numbering' => $user->can('letters.numbering.manage'),
                'reports' => $user->can('letters.reports.view'),
            ],
        ]);
    }
}
