<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Models\CourtCaseOfficer;
use App\Models\InstitutionCourtCase;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InstitutionCourtCaseNotification;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cases filed at the administrative tribunal against institutions registered
 * here (court-ms Flow 7, ADR 0013 #14), however they were filed: from My Portal,
 * by a lawyer, or by the registry. `court-ms:poll` reads them after the last
 * cursor and gives each to the officer the institution named to follow its
 * court cases; with no officer named, the institution's tribunal officers are
 * told so that one takes it.
 */
final class InstitutionCourtCaseInbox
{
    public const CURSOR_KEY = 'institution_cases_cursor';

    /** Who sees the institution's cases: its tribunal officers, and anyone with the Court Cases module. */
    public const PERMISSIONS = CourtLetterInbox::PERMISSIONS;

    /** Who assigns them and asks to appeal: the institution's tribunal officers. */
    public const MANAGE = 'grievances.tribunal';

    public function __construct(
        private readonly CourtMsClient $client,
        private readonly CourtMsStatusSync $state,
        private readonly OrganizationScopeService $scope,
    ) {}

    /**
     * @return array{read: int, stored: int}
     *
     * @throws CourtMsException
     */
    public function run(int $maxPages = 10): array
    {
        $cursor = (int) ($this->state->state(self::CURSOR_KEY) ?? 0);
        $read = 0;
        $stored = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->client->institutionNewCases($cursor);
            foreach ($result['data'] as $row) {
                $read++;
                if ($this->receive($row)) {
                    $stored++;
                }
                $cursor = max($cursor, (int) ($row['cursor'] ?? 0));
                $this->state->remember(self::CURSOR_KEY, (string) $cursor);
            }
            if (! $result['has_more']) {
                break;
            }
        }

        return ['read' => $read, 'stored' => $stored];
    }

    public static function canRead(User $user): bool
    {
        return collect(self::PERMISSIONS)->contains(fn (string $permission): bool => $user->can($permission));
    }

    /** The cases a person may see: within their organization scope. */
    public function visibleTo(User $user): Builder
    {
        return $this->scope->applyOrganizationScope(InstitutionCourtCase::query(), $user, 'organization_id');
    }

    /** Whether a person may assign this institution's cases and ask to appeal them. */
    public function canManage(User $user, string $organizationId): bool
    {
        return $user->can(self::MANAGE) && $this->scope->canExercisePermission($user, self::MANAGE, $organizationId);
    }

    /**
     * Who may follow an institution's court cases: active people with the tribunal
     * permission over it.
     *
     * @return Collection<int, User>
     */
    public function candidates(string $organizationId): Collection
    {
        return User::permission(self::MANAGE)->where('status', 'active')->orderBy('name')->limit(200)->get()
            ->filter(fn (User $user): bool => $this->scope->canExercisePermission($user, self::MANAGE, $organizationId))
            ->values();
    }

    /** Gives a case to an officer and tells them. */
    public function assign(InstitutionCourtCase $case, User $officer): void
    {
        $case->forceFill(['officer_user_id' => $officer->getKey(), 'assigned_at' => now()])->save();
        $officer->notify(new InstitutionCourtCaseNotification('assigned', (string) $case->case_number, route('tribunal-cases.institution-cases.show', $case->getKey(), absolute: false)));
    }

    /** @param  array<string, mixed>  $row */
    private function receive(array $row): bool
    {
        $caseId = (int) ($row['case_id'] ?? 0);
        $organization = Organization::query()->find((string) ($row['organization_uuid'] ?? ''));
        if ($caseId < 1 || $organization === null || InstitutionCourtCase::query()->where('court_case_id', $caseId)->exists()) {
            return false;
        }

        $case = InstitutionCourtCase::query()->create([
            'court_case_id' => $caseId,
            'organization_id' => $organization->getKey(),
            'case_number' => mb_substr((string) ($row['case_number'] ?? $caseId), 0, 80),
            'applicants' => array_values(array_map(fn (mixed $name): string => mb_substr(strip_tags((string) $name), 0, 255), (array) ($row['applicants'] ?? []))),
            'case_type' => is_string($row['case_type'] ?? null) ? mb_substr($row['case_type'], 0, 255) : null,
            'filed_at' => $this->date($row['filed_at'] ?? null),
            'status' => is_string($row['status'] ?? null) ? mb_substr($row['status'], 0, 30) : null,
            'review_status' => is_string($row['review_status'] ?? null) ? mb_substr($row['review_status'], 0, 30) : null,
            'source' => is_string($row['source'] ?? null) ? mb_substr($row['source'], 0, 40) : null,
            'received_at' => now(),
        ]);

        $officer = CourtCaseOfficer::query()->where('organization_id', $organization->getKey())->first()?->user;
        if ($officer instanceof User && $officer->status === 'active') {
            $this->assign($case, $officer);
        } else {
            $this->notifyTribunalOfficers($case);
        }

        return true;
    }

    /** No officer named: the institution's tribunal officers are told, so that one takes the case. */
    private function notifyTribunalOfficers(InstitutionCourtCase $case): void
    {
        $url = route('tribunal-cases.institution-cases.show', $case->getKey(), absolute: false);
        $this->candidates((string) $case->organization_id)
            ->reject(fn (User $user): bool => $this->scope->hasUnrestrictedRole($user))
            ->each(fn (User $user) => $user->notify(new InstitutionCourtCaseNotification('unassigned', (string) $case->case_number, $url)));
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
