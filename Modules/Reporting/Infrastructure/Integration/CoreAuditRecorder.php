<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Integration;

use App\Backbone\Contracts\AuditTrail;
use Carbon\CarbonImmutable;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Infrastructure\Persistence\Models\ObligationEvent;

/**
 * Two records per decision, both append-only for the application:
 * the application audit log (through the Core's AuditTrail contract, with its
 * restricted access) and the obligation's own history shown on its page.
 */
final readonly class CoreAuditRecorder implements AuditRecorder
{
    /** The events that have an entry in the application audit log (App\Enums\AuditEventType, "reporting.*"). */
    private const AUDITED = [
        'type_saved', 'template_published', 'schedule_saved', 'cycle_published', 'submitted', 'review_decided', 'review_reassigned',
        'reopened', 'extension_requested', 'extension_decided', 'applicability_changed', 'obligation_reassigned', 'follow_up_saved',
        'consolidated', 'file_downloaded', 'exported', 'settings_changed',
    ];

    public function __construct(private AuditTrail $trail) {}

    public function record(string $event, ?ActorContext $actor, string $subjectType, string $subjectId, ?string $organizationId = null, array $data = [], ?string $reason = null, ?string $obligationId = null): void
    {
        if ($obligationId !== null) {
            ObligationEvent::query()->create([
                'obligation_id' => $obligationId, 'event' => $event, 'actor_user_id' => $actor?->userId ?: null, 'actor_name' => $actor?->name,
                'data' => $reason === null ? $data : [...$data, 'reason' => $reason], 'created_at' => CarbonImmutable::now('UTC'),
            ]);
        }
        if (in_array($event, self::AUDITED, true)) {
            $this->trail->record("reporting.{$event}", $actor?->userId ?: null, $subjectType, $subjectId, $organizationId, null, $data, $reason);
        }
    }
}
