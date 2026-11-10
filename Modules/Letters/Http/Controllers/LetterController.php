<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LetterPresenter;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Application\Services\LetterStandards;
use Modules\Letters\Application\Services\RegistryQuery;
use Modules\Letters\Domain\Enums\DispatchMode;
use Modules\Letters\Domain\Enums\LetterClassification;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRegistration;

/** The registry (every institution in scope, with its tabs and filters) and the letter page. */
final class LetterController extends Controller
{
    public function __construct(
        private readonly LettersAccess $access,
        private readonly LetterPresenter $presenter,
        private readonly LetterLookups $lookups,
        private readonly RegistryQuery $registry,
        private readonly LetterStandards $standards,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Letter::class);
        $user = $request->user();
        $tab = (string) $request->string('tab', 'registry');
        $filters = [
            'tab' => $tab,
            'direction' => (string) $request->string('direction'),
            'status' => (string) $request->string('status'),
            'organization_id' => (string) $request->string('organization_id'),
            'q' => trim((string) $request->string('q')),
            'from' => (string) $request->string('from'),
            'to' => (string) $request->string('to'),
            'file_class_id' => (string) $request->string('file_class_id'),
        ];

        $rows = match ($tab) {
            'drafts' => $this->drafts($request, $filters),
            'mine' => $this->mine($request),
            default => $this->registry->build($user, $filters, $tab === 'inbox')->paginate(25)->withQueryString()
                ->through(fn (LetterRegistration $r): array => $this->presenter->row($r->letter, $r, $user)),
        };

        return Inertia::render('Letters/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'organizations' => $this->lookups->actingOrganizations($user, 'letters.view'),
            'directions' => LetterDirection::values(),
            'registration_statuses' => RegistrationStatus::values(),
            'letter_statuses' => LetterStatus::values(),
            'classifications' => LetterClassification::values(),
            'file_classes' => $filters['organization_id'] !== '' ? $this->standards->fileClassesFor($filters['organization_id']) : (count($organizations = $this->lookups->actingOrganizations($user, 'letters.view')) === 1 ? $this->standards->fileClassesFor($organizations[0]['id']) : []),
            'can' => ['register' => $user->can('letters.register'), 'compose' => $user->can('letters.compose'), 'export' => $user->can('letters.export')],
        ]);
    }

    public function show(Request $request, Letter $letter): Response
    {
        $this->authorize('view', $letter);
        $user = $request->user();
        $letter->load(['registrations.routings', 'routings']);

        $routable = $letter->registrations->filter(fn (LetterRegistration $r): bool => $this->access->canRoute($user, $r));
        $lookups = [];
        foreach ($routable as $registration) {
            $lookups[$registration->organization_id] = [
                'units' => $this->lookups->units($registration->organization_id),
                'people' => $this->lookups->people($registration->organization_id),
            ];
        }

        return Inertia::render('Letters/Show', [
            'letter' => $this->presenter->full($letter, $user),
            'routing_lookups' => (object) $lookups,
            'dispatch_modes' => DispatchMode::values(),
            'reply_organizations' => $user->can('reply', $letter)
                ? $letter->registrations->filter(fn (LetterRegistration $r): bool => $r->direction === LetterDirection::Incoming && $this->access->canActFor($user, 'letters.compose', $r->organization_id))
                    ->map(fn (LetterRegistration $r): array => ['id' => $r->organization_id, 'name_en' => $r->organization?->name_en, 'name_am' => $r->organization?->name_am])->values()->all()
                : [],
        ]);
    }

    /** @param array<string, string> $filters */
    private function drafts(Request $request, array $filters): mixed
    {
        $user = $request->user();
        $query = $this->access->scopeLetters(Letter::query(), $user)
            ->with(['organization', 'senderOrganization', 'recipientOrganization', 'unit', 'registrations.routings', 'routings'])
            ->whereIn('status', [LetterStatus::Draft->value, LetterStatus::PendingApproval->value, LetterStatus::Approved->value, LetterStatus::Cancelled->value]);
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['organization_id'] !== '') {
            $query->where('organization_id', $filters['organization_id']);
        }
        if ($filters['q'] !== '') {
            $query->where(fn (Builder $w) => $w->where('subject', 'like', "%{$filters['q']}%")->orWhere('file_code', 'like', "%{$filters['q']}%"));
        }

        return $query->orderByDesc('updated_at')->paginate(25)->withQueryString()
            ->through(fn (Letter $l): array => $this->presenter->row($l, null, $user));
    }

    private function mine(Request $request): mixed
    {
        $user = $request->user();

        return LetterRegistration::query()
            ->whereHas('routings', fn (Builder $r) => $r->where('to_user_id', $user->id)->whereIn('status', [RoutingStatus::Pending->value, RoutingStatus::Acknowledged->value]))
            ->with(['letter.organization', 'letter.senderOrganization', 'letter.recipientOrganization', 'letter.unit', 'letter.routings', 'organization', 'routings'])
            ->orderByDesc('updated_at')->paginate(25)->withQueryString()
            ->through(fn (LetterRegistration $r): array => $this->presenter->row($r->letter, $r, $user));
    }
}
