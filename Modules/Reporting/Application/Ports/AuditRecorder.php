<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

use Modules\Reporting\Application\Data\ActorContext;

/**
 * Evidence of a decision or disclosure: one entry in the application's
 * append-only audit log, and one in the obligation's own history when an
 * obligation is named.
 */
interface AuditRecorder
{
    /**
     * @param  string  $event  e.g. "submitted", "review_decided"; the module's audit event name without its prefix
     * @param  array<string, mixed>  $data
     */
    public function record(string $event, ?ActorContext $actor, string $subjectType, string $subjectId, ?string $organizationId = null, array $data = [], ?string $reason = null, ?string $obligationId = null): void;
}
