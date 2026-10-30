<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\Grievance\GrievanceEventVisibility;
use App\Models\Grievance;
use App\Models\GrievanceCaseEvent;
use App\Models\User;

/**
 * The unified case timeline. Complainant-visible events carry safe progress
 * only (what happened, when, at which level); everything else is internal.
 * `data` holds display parameters (handler names, dates, codes), never text a
 * handler typed.
 */
final class GrievanceTimeline
{
    /** Events the complainant may see on My Portal. */
    public const COMPLAINANT_EVENTS = [
        'submitted', 'resubmitted', 'returned_for_correction', 'rejected_at_intake', 'accepted',
        'stage_received', 'review_started', 'information_requested', 'information_responded',
        'hearing_scheduled', 'hearing_cancelled', 'decision_issued', 'auto_escalated', 'escalated',
        'appealed', 'referred', 'withdrawal_requested', 'withdrawn', 'withdrawal_rejected',
        'closed', 'reopened', 'letter_issued',
    ];

    /** @param  array<string, mixed>  $data */
    public function record(Grievance $grievance, string $event, ?User $actor = null, array $data = [], ?string $stageId = null, ?GrievanceEventVisibility $visibility = null): GrievanceCaseEvent
    {
        $visibility ??= in_array($event, self::COMPLAINANT_EVENTS, true)
            ? GrievanceEventVisibility::Complainant
            : GrievanceEventVisibility::Internal;

        return GrievanceCaseEvent::query()->create([
            'grievance_id' => $grievance->getKey(),
            'case_stage_id' => $stageId ?? $grievance->current_stage_id,
            'event' => $event,
            'visibility' => $visibility,
            'actor_user_id' => $actor?->getKey(),
            'data' => $data === [] ? null : $data,
            'occurred_at' => now(),
        ]);
    }
}
