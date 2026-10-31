<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\ApplicantSignInTicket;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Services\Notifications\PortalNotificationFeed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Flow 4 of the EUISIS integration (docs/euisis-integration.md): an EUISIS
 * employee who files or follows tribunal cases does it as a court applicant.
 * The EUISIS employee portal reads the employee's court summary and, for any
 * action, asks for a one-time ticket that signs them into the applicant portal.
 *
 * An employee is the applicant linked to their EUISIS employee UUID; before the
 * first link, the applicant with their national ID (then their email). A
 * match already linked to another employee is a conflict, never a takeover.
 */
final class EmployeeCourtAccess
{
    /** Where a ticket may send the applicant: target => route name. */
    public const TARGETS = [
        'dashboard' => 'court.applicant.dashboard',
        'cases' => 'court.applicant.cases.index',
        'new_case' => 'court.applicant.cases.create',
        'notifications' => 'court.applicant.notifications.index',
        'profile' => 'court.applicant.profile.edit',
    ];

    /**
     * The applicant this employee is, without changing anything.
     *
     * @param  array{uuid: string, national_id?: ?string, email?: ?string}  $employee
     */
    public function find(array $employee): ?Applicant
    {
        $linked = Applicant::query()->where('euisis_employee_uuid', $employee['uuid'])->first();
        if ($linked !== null) {
            return $linked;
        }

        $match = $this->match($employee);

        // A court account already linked to another employee is not this employee's.
        return $match !== null && $match->euisis_employee_uuid === null ? $match : null;
    }

    /**
     * The applicant this employee is, linked on first use and opened when there is none.
     *
     * @param  array<string, mixed>  $employee  uuid, national_id, first/middle/last name, gender, email, phone, position, organization_name, address
     *
     * @throws EmployeeCourtAccessException
     */
    public function findOrOpen(array $employee): Applicant
    {
        $linked = Applicant::query()->where('euisis_employee_uuid', $employee['uuid'])->first();
        if ($linked !== null) {
            return $linked;
        }

        $match = $this->match($employee);
        if ($match !== null) {
            if ($match->euisis_employee_uuid !== null) {
                throw new EmployeeCourtAccessException('linked_to_another_employee', 'The court account with this national ID or email is linked to another employee.');
            }
            $match->forceFill(['euisis_employee_uuid' => $employee['uuid']])->save();

            return $match;
        }

        return $this->open($employee);
    }

    /**
     * The employee's court summary for the EUISIS employee portal: their cases (newest
     * first) with the next hearing of each, the counts on the portal card, and the unseen
     * notices the applicant portal's bell would show.
     *
     * @return array<string, mixed>
     */
    public function summary(Applicant $applicant): array
    {
        $cases = DB::table('court_cases as c')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')
            ->leftJoin('court_cases as mc', 'mc.id', '=', 'c.merged_into_case_id')
            ->whereIn('c.id', CaseParties::caseIdsFor((int) $applicant->id))
            ->orderByDesc('c.created_at')
            ->limit(25)
            ->get(['c.id', 'c.case_number', 'c.title', 'c.respondent_name', 'c.status', 'c.review_status', 'c.filing_date', 'c.created_at', 'ct.name as case_type',
                'c.merged_into_case_id', 'mc.case_number as merged_into_number']);

        $nextHearings = DB::table('court_case_hearings')
            ->whereIn('case_id', $cases->pluck('id')->all())
            ->where('hearing_at', '>=', now())
            ->selectRaw('case_id, MIN(hearing_at) as next_at')
            ->groupBy('case_id')
            ->pluck('next_at', 'case_id');

        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;
        $feed = PortalNotificationFeed::forApplicant((int) $applicant->id)->feed(1, 5);

        return [
            'applicant' => ['name' => trim(preg_replace('/\s+/', ' ', $applicant->first_name.' '.$applicant->middle_name.' '.$applicant->last_name)), 'is_active' => (bool) $applicant->is_active],
            'counts' => [
                'cases' => (int) DB::table('court_cases')->whereIn('id', CaseParties::caseIdsFor((int) $applicant->id))->count(),
                'open' => (int) DB::table('court_cases')->whereIn('id', CaseParties::caseIdsFor((int) $applicant->id))->whereNotIn('status', ['closed', 'dismissed'])->count(),
                'awaiting_review' => (int) DB::table('court_cases')->whereIn('id', CaseParties::caseIdsFor((int) $applicant->id))->whereIn('review_status', ['awaiting_review', 'returned'])->count(),
                'upcoming_hearings' => (int) DB::table('court_case_hearings as h')->join('court_cases as c', 'c.id', '=', 'h.case_id')
                    ->whereIn('c.id', CaseParties::caseIdsFor((int) $applicant->id))->where('h.hearing_at', '>=', now())->count(),
                'unread_notices' => (int) $feed['unread_count'],
            ],
            'cases' => $cases->map(fn (object $case): array => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'title' => (string) $case->title,
                // The case title is the applicant's own name; the employee's list names the other party.
                'respondent' => $case->respondent_name !== null && trim((string) $case->respondent_name) !== '' ? (string) $case->respondent_name : null,
                'case_type' => $case->case_type,
                'status' => (string) ($case->status ?? 'pending'),
                'review_status' => (string) ($case->review_status ?? 'accepted'),
                'filed_at' => $iso($case->filing_date ?? $case->created_at),
                'next_hearing_at' => $iso($nextHearings[$case->id] ?? null),
                // lead, co_applicant, intervener or lawyer; a merged case names the case it joined.
                'role' => CaseParties::roleOf((int) $applicant->id, (int) $case->id),
                'merged_into' => $case->merged_into_case_id !== null ? ['id' => (int) $case->merged_into_case_id, 'case_number' => (string) $case->merged_into_number] : null,
            ])->values()->all(),
            // Titles and text only, with the case each concerns: EUISIS opens it in My Portal.
            'notices' => collect($feed['items'])->map(fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['title'],
                'message' => $item['message'],
                'created_at' => $item['created_at'],
                'case_id' => $item['case_id'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * A one-time ticket that signs the applicant in and opens the target.
     *
     * @return array{url: string, expires_at: string}
     *
     * @throws EmployeeCourtAccessException
     */
    public function issueTicket(Applicant $applicant, string $target, ?IntegrationClient $client): array
    {
        if (! $applicant->is_active) {
            throw new EmployeeCourtAccessException('account_inactive', 'The court account is deactivated.');
        }
        $this->assertTarget($applicant, $target);

        $plain = Str::random(64);
        $ticket = ApplicantSignInTicket::query()->create([
            'applicant_id' => $applicant->id,
            'integration_client_id' => $client?->id,
            'token_hash' => ApplicantSignInTicket::hashToken($plain),
            'target' => $target,
            'expires_at' => now()->addSeconds(ApplicantSignInTicket::LIFETIME_SECONDS),
        ]);

        return ['url' => route('court.applicant.euisis.sign-in', ['ticket' => $plain]), 'expires_at' => $ticket->expires_at->toIso8601String()];
    }

    /**
     * Uses a ticket: the applicant to sign in and where to send them, or null when the
     * ticket is unknown, used or expired. A ticket works once.
     *
     * @return array{applicant: Applicant, redirect: string}|null
     */
    public function redeem(string $plain, ?string $ip): ?array
    {
        $ticket = ApplicantSignInTicket::query()
            ->where('token_hash', ApplicantSignInTicket::hashToken($plain))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
        if ($ticket === null) {
            return null;
        }

        // Claimed atomically, so two tabs racing for one ticket cannot both sign in.
        $claimed = ApplicantSignInTicket::query()->whereKey($ticket->id)->whereNull('used_at')
            ->update(['used_at' => now(), 'used_ip' => $ip, 'updated_at' => now()]);
        $applicant = $ticket->applicant;
        if ($claimed !== 1 || $applicant === null || ! $applicant->is_active) {
            return null;
        }

        return ['applicant' => $applicant, 'redirect' => $this->targetUrl($applicant, $ticket->target)];
    }

    /** @param  array<string, mixed>  $employee */
    private function match(array $employee): ?Applicant
    {
        $nationalId = preg_replace('/\D+/', '', (string) ($employee['national_id'] ?? ''));
        if ($nationalId !== '') {
            $byNationalId = Applicant::query()->where('national_id_number', $nationalId)->first();
            if ($byNationalId !== null) {
                return $byNationalId;
            }
        }

        $email = Str::lower(trim((string) ($employee['email'] ?? '')));
        if ($email === '') {
            return null;
        }
        $byEmail = Applicant::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        // An email match with a different national ID is someone else.
        // (The model shows the ID in groups of four; compare the digits.)
        if ($byEmail !== null && $nationalId !== '' && preg_replace('/\D+/', '', (string) $byEmail->national_id_number) !== $nationalId) {
            throw new EmployeeCourtAccessException('identity_mismatch', 'A court account uses this email with a different national ID.');
        }

        return $byEmail;
    }

    /** @param  array<string, mixed>  $employee */
    private function open(array $employee): Applicant
    {
        $nationalId = preg_replace('/\D+/', '', (string) ($employee['national_id'] ?? ''));
        $phone = trim((string) ($employee['phone'] ?? ''));
        $email = Str::lower(trim((string) ($employee['email'] ?? '')));
        // applicants.gender is male or female (2026_02_04_000002); it is never guessed.
        $gender = in_array($employee['gender'] ?? null, ['male', 'female'], true) ? (string) $employee['gender'] : '';
        if ($nationalId === '' || $phone === '' || $email === '' || $gender === '') {
            throw new EmployeeCourtAccessException('employee_record_incomplete', 'Opening a court account needs the employee\'s national ID, phone number, email and gender.');
        }
        if (Applicant::query()->where('phone', $phone)->exists()) {
            throw new EmployeeCourtAccessException('phone_in_use', 'Another court account uses this phone number.');
        }

        $applicant = new Applicant;
        $applicant->forceFill([
            'first_name' => (string) $employee['first_name'],
            'middle_name' => (string) ($employee['middle_name'] ?? ''),
            'last_name' => (string) $employee['last_name'],
            'gender' => $gender,
            'position' => $employee['position'] ?? null,
            'organization_name' => $employee['organization_name'] ?? null,
            // A required column (2026_02_04_000001): empty when EUISIS has no address.
            'address' => (string) ($employee['address'] ?? ''),
            'phone' => $phone,
            'email' => $email,
            'national_id_number' => $nationalId,
            'euisis_employee_uuid' => $employee['uuid'],
            // The employee signs in through EUISIS; this password is never shown or used.
            'password' => Hash::make(Str::random(48)),
            'is_active' => true,
            'is_lawyer' => false,
            // EUISIS vouches for the address it holds for its employee.
            'email_verified_at' => now(),
        ])->save();

        return $applicant;
    }

    private function assertTarget(Applicant $applicant, string $target): void
    {
        if (isset(self::TARGETS[$target])) {
            return;
        }
        if (preg_match('/^case:(\d+)$/', $target, $m) === 1
            && CaseParties::canFollow((int) $applicant->id, (int) $m[1])) {
            return;
        }

        throw new EmployeeCourtAccessException('unknown_target', 'The court portal has no such page for this employee.');
    }

    private function targetUrl(Applicant $applicant, string $target): string
    {
        if (preg_match('/^case:(\d+)$/', $target, $m) === 1
            && CaseParties::canFollow((int) $applicant->id, (int) $m[1])) {
            return route('court.applicant.cases.show', (int) $m[1]);
        }

        return route(self::TARGETS[$target] ?? self::TARGETS['dashboard']);
    }
}
