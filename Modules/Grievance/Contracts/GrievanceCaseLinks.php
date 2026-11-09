<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * What the administrative tribunal and the court-ms integration need of a
 * grievance case (ADR 0017), by its id: who may see or act on it, its
 * timeline and audit entries, notices about it, its statutory deadline
 * alerts and the evidence a referral may carry.
 */
interface GrievanceCaseLinks
{
    public function canView(User $user, string $grievanceId): bool;

    /** Whether the user handles the case's current stage. */
    public function handlesCurrent(User $user, string $grievanceId): bool;

    /** A subquery of the ids of the open cases assigned to the user (for whereIn). */
    public function assignedIds(User $user): Builder;

    /** Adds an entry to the case timeline. */
    public function recordEvent(string $grievanceId, string $event, ?User $actor = null, array $data = []): void;

    /** Writes an audit entry on an Eloquent record tied to a grievance (in the grievance's organization). */
    public function audit(AuditEventType $event, ?User $actor, object $subject, array $new = [], ?array $old = null, ?string $reason = null): void;

    /** @param iterable<User> $users */
    public function notifyUsers(iterable $users, string $kind, string $grievanceId, ?string $url = null): void;

    /**
     * Who a statutory deadline on the case alerts: its reference, its page and
     * the handlers of its current stage.
     *
     * @return array{0: ?string, 1: ?string, 2: list<User>}
     */
    public function deadlineAlertTarget(string $grievanceId): array;

    /**
     * Submitted or accepted PDF evidence of the case up to $maxBytes, oldest first.
     *
     * @return list<array{id: string, title: ?string, original_name: ?string, size_bytes: ?int, submitted_at: ?string}>
     */
    public function referableEvidence(string $grievanceId, int $maxBytes): array;

    /**
     * The files of the given evidence, as a referral carries them.
     *
     * @param  list<string>  $evidenceIds
     * @return list<array{name: string, contents: string, title: string}>
     */
    public function evidenceFiles(array $evidenceIds): array;
}
