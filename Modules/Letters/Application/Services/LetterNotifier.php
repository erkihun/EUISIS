<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Domain\Models\Letter;

/**
 * Who hears about a letter: the registrars of the receiving institution when
 * a letter arrives, the signatory when a draft awaits signature, the author
 * when it is returned or signed, the addressee of a routing step.
 */
class LetterNotifier
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    /** Active users holding $permission whose scope reaches the institution (at most 50). */
    public function usersFor(string $permission, string $organizationId): Collection
    {
        $candidates = User::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereHas('roles.permissions', fn ($p) => $p->where('name', $permission))->orWhereHas('permissions', fn ($p) => $p->where('name', $permission)))
            ->where(fn ($q) => $q->whereHas('organizationScopes', fn ($s) => $s->where('is_active', true)->where(fn ($w) => $w->where('organization_id', $organizationId)->orWhere('scope_type', OrganizationScopeType::Citywide->value)))
                ->orWhereHas('employee.currentAssignment', fn ($a) => $a->where('organization_id', $organizationId)))
            ->limit(200)->get();

        return $candidates->filter(fn (User $u): bool => $this->scope->canAccessOrganization($u, $organizationId))->take(50)->values();
    }

    public function send(iterable $users, LetterNotification $notification): void
    {
        $recipients = collect($users)->filter()->unique('id');
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }

    /** @return array<string, string> */
    public function params(Letter $letter): array
    {
        return ['subject' => mb_substr((string) $letter->subject, 0, 120), 'number' => (string) ($letter->referenceNumber() ?? '—')];
    }
}
