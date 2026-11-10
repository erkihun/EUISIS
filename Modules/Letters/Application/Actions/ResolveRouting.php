<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Enums\RegistrationStatus;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\LetterRouting;

/** The addressee acknowledges a routing step, completes it with an outcome, or returns it. */
final class ResolveRouting
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
    ) {}

    public function acknowledge(LetterRouting $routing, User $actor): LetterRouting
    {
        return DB::transaction(function () use ($routing, $actor): LetterRouting {
            $routing->forceFill(['status' => RoutingStatus::Acknowledged->value, 'acknowledged_at' => now()])->save();
            $registration = $routing->registration;
            if ($registration !== null && $registration->status !== RegistrationStatus::Closed) {
                $registration->forceFill(['status' => RegistrationStatus::InProgress->value])->save();
            }
            $this->events->record($routing->letter, LetterEventType::Acknowledged, $actor, ['step' => $routing->step], $registration);
            $this->audit->record(AuditEventType::LetterRoutingAcknowledged, $actor, $routing->letter, $registration?->organization_id, ['step' => $routing->step]);

            return $routing;
        });
    }

    public function complete(LetterRouting $routing, string $outcome, User $actor): LetterRouting
    {
        return $this->finish($routing, RoutingStatus::Completed, LetterEventType::Completed, AuditEventType::LetterRoutingCompleted, $outcome, $actor);
    }

    public function return(LetterRouting $routing, string $outcome, User $actor): LetterRouting
    {
        return $this->finish($routing, RoutingStatus::Returned, LetterEventType::RoutingReturned, AuditEventType::LetterRoutingReturned, $outcome, $actor);
    }

    private function finish(LetterRouting $routing, RoutingStatus $status, LetterEventType $event, AuditEventType $audit, string $outcome, User $actor): LetterRouting
    {
        return DB::transaction(function () use ($routing, $status, $event, $audit, $outcome, $actor): LetterRouting {
            $routing->forceFill(['status' => $status->value, 'completed_at' => now(), 'outcome' => $outcome, 'acknowledged_at' => $routing->acknowledged_at ?? now()])->save();
            $registration = $routing->registration;
            $this->events->record($routing->letter, $event, $actor, ['step' => $routing->step, 'outcome' => mb_substr($outcome, 0, 500)], $registration);
            $this->audit->record($audit, $actor, $routing->letter, $registration?->organization_id, ['step' => $routing->step], $outcome);

            return $routing;
        });
    }
}
