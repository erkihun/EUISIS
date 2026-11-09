<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Models\AdministrativeTribunalCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Grievance\Contracts\GrievanceCaseLinks;

/**
 * Reads what changed on referred cases in court-ms and keeps the tribunal
 * register in step (ADR 0013, Flow 3): review state and reason, case status,
 * hearing date, decision date and summary. Each change is written to the
 * grievance history, and returns, hearings and decisions notify the people
 * working the tribunal case. Runs every ten minutes (`court-ms:poll`).
 */
final class CourtMsStatusSync
{
    public const CURSOR_KEY = 'updates_cursor';

    public const LAST_RUN_KEY = 'last_run_at';

    public const LAST_ERROR_KEY = 'last_error';

    private const MAX_PAGES = 20;

    public function __construct(
        private readonly CourtMsClient $client,
        private readonly GrievanceCaseLinks $grievances,
    ) {}

    /** @return array{read: int, applied: int} */
    public function run(): array
    {
        $read = 0;
        $applied = 0;
        $cursor = (int) ($this->state(self::CURSOR_KEY) ?? 0);

        try {
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $result = $this->client->updates($cursor);
                foreach ($result['data'] as $update) {
                    $read++;
                    $applied += $this->apply($update) ? 1 : 0;
                }
                $cursor = $result['next_cursor'];
                $this->remember(self::CURSOR_KEY, (string) $cursor);
                if (! $result['has_more']) {
                    break;
                }
            }
            $this->remember(self::LAST_ERROR_KEY, null);
        } catch (CourtMsException $e) {
            $this->remember(self::LAST_ERROR_KEY, mb_substr($e->getMessage(), 0, 500));
            throw $e;
        } finally {
            $this->remember(self::LAST_RUN_KEY, now()->toIso8601String());
        }

        return ['read' => $read, 'applied' => $applied];
    }

    /** Applies one row of the court-ms update list; false when no tribunal case matches it. */
    public function apply(array $update): bool
    {
        $referralId = (string) ($update['referral_id'] ?? '');
        $caseNumber = (string) ($update['case_number'] ?? '');
        if (! Str::isUuid($referralId) && $caseNumber === '') {
            return false;
        }
        // The grievance UUID was the referral id; the court-ms number is the fallback.
        $case = AdministrativeTribunalCase::query()->with('grievance')
            ->where(function ($q) use ($referralId, $caseNumber): void {
                if (Str::isUuid($referralId)) {
                    $q->where('grievance_id', $referralId);
                }
                if ($caseNumber !== '') {
                    $q->orWhere('court_case_number', $caseNumber);
                }
            })
            ->first();
        if ($case === null || $case->grievance === null) {
            return false;
        }

        $changed = (array) ($update['changed'] ?? []);
        $previousReview = $case->court_review_status;
        $hearing = isset($update['hearing_at']) ? CarbonImmutable::parse((string) $update['hearing_at']) : null;
        $decisionDate = isset($update['decision_date']) ? CarbonImmutable::parse((string) $update['decision_date']) : null;

        $values = [
            'court_case_number' => $update['case_number'] ?? $case->court_case_number,
            'court_review_status' => $update['review_status'] ?? $case->court_review_status,
            'court_review_reason' => $update['review_reason'] ?? null,
            'court_status' => $update['status'] ?? $case->court_status,
            'court_synced_at' => now(),
            'referral_state' => AdministrativeTribunalCase::REFERRAL_SENT,
        ];
        // The register's own fields follow court-ms (ADR 0013 status mapping).
        $values['status'] = $this->registerStatus($case->status, (string) $values['court_review_status'], (string) $values['court_status'], $hearing, $decisionDate);
        if ($hearing !== null) {
            $values['hearing_date'] = $hearing->toDateString();
        }
        if ($decisionDate !== null) {
            $values['decision_date'] = $decisionDate->toDateString();
            $values['decision_summary'] = $update['decision_summary'] ?? $case->decision_summary;
        }

        DB::transaction(function () use ($case, $values, $update, $changed, $previousReview, $hearing, $decisionDate): void {
            $case->forceFill($values)->save();

            $events = [];
            if (in_array('review_status', $changed, true) && $values['court_review_status'] !== $previousReview) {
                $events[] = ['court_ms_review_changed', ['review_status' => $values['court_review_status'], 'reason' => $values['court_review_reason']]];
            }
            if (in_array('hearing_at', $changed, true) && $hearing !== null) {
                $events[] = ['court_ms_hearing_scheduled', ['hearing_at' => $hearing->format('Y-m-d H:i')]];
            }
            if (in_array('decision_date', $changed, true) && $decisionDate !== null) {
                $events[] = ['court_ms_decision_issued', ['decision_date' => $decisionDate->toDateString()]];
            }
            if (in_array('status', $changed, true) && in_array($values['court_status'], ['closed', 'dismissed'], true)) {
                $events[] = ['court_ms_case_closed', ['status' => $values['court_status']]];
            }
            foreach ($events as [$event, $data]) {
                $this->grievances->recordEvent((string) $case->grievance_id, $event, null, ['court_case_number' => $update['case_number'] ?? null, ...$data]);
            }

            $kind = match (true) {
                in_array($values['court_review_status'], ['returned', 'rejected'], true) && in_array('review_status', $changed, true) => 'court_ms_returned',
                in_array('decision_date', $changed, true) && $decisionDate !== null => 'court_ms_decided',
                in_array('hearing_at', $changed, true) && $hearing !== null => 'court_ms_hearing',
                default => null,
            };
            if ($kind !== null) {
                $this->grievances->notifyUsers($this->people($case), $kind, (string) $case->grievance_id, '/tribunal-cases/'.$case->getKey());
            }
        });

        return true;
    }

    public function state(string $key): ?string
    {
        $value = DB::table('court_ms_sync_state')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    public function remember(string $key, ?string $value): void
    {
        DB::table('court_ms_sync_state')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** open → hearing → decided → closed, never backwards (the register's statuses). */
    private function registerStatus(string $current, string $review, string $courtStatus, ?CarbonImmutable $hearing, ?CarbonImmutable $decision): string
    {
        $target = match (true) {
            in_array($courtStatus, ['closed', 'dismissed'], true) => 'closed',
            $decision !== null => 'decided',
            $review === 'accepted' && $hearing !== null => 'hearing',
            default => 'open',
        };
        $rank = ['open' => 0, 'hearing' => 1, 'decided' => 2, 'closed' => 3];

        return ($rank[$target] ?? 0) >= ($rank[$current] ?? 0) ? $target : $current;
    }

    /** @return list<User> */
    private function people(AdministrativeTribunalCase $case): array
    {
        $case->loadMissing(['assignedToUser', 'createdByUser', 'referredByUser']);

        return collect([$case->assignedToUser, $case->referredByUser, $case->createdByUser])
            ->filter()->unique('id')->values()->all();
    }
}
