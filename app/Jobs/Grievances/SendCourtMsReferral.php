<?php

declare(strict_types=1);

namespace App\Jobs\Grievances;

use App\Models\AdministrativeTribunalCase;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\CourtMsReferrals;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one tribunal referral to court-ms (ADR 0013). Retried with growing
 * delays while court-ms is unreachable or busy; a refusal of the content
 * fails at once. The officer sees the state on the tribunal case page.
 */
class SendCourtMsReferral implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public int $timeout = 120;

    public function __construct(public readonly string $tribunalCaseId)
    {
        $this->onQueue('integrations');
    }

    /** @return list<int> seconds between attempts: 1 and 5 minutes, then 15, 30 and hourly */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 3600, 3600];
    }

    public function uniqueId(): string
    {
        return 'court-ms-referral:'.$this->tribunalCaseId;
    }

    public function handle(CourtMsReferrals $referrals): void
    {
        $case = AdministrativeTribunalCase::query()->with('grievance')->find($this->tribunalCaseId);
        if ($case === null || $case->referral_state !== AdministrativeTribunalCase::REFERRAL_QUEUED) {
            return;
        }

        try {
            $referrals->send($case);
        } catch (CourtMsException $e) {
            if (! $e->retryable) {
                $this->fail($e);

                return;
            }
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $case = AdministrativeTribunalCase::query()->with('grievance')->find($this->tribunalCaseId);
        if ($case !== null && $case->referral_state === AdministrativeTribunalCase::REFERRAL_QUEUED) {
            app(CourtMsReferrals::class)->fail($case, $exception?->getMessage() ?? 'court-ms did not accept the referral.');
        }
    }
}
