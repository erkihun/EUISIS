<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts;

use App\Models\User;

/**
 * An employee's own grievances for a case at the court (ADR 0017): only what
 * they can already see in My Grievances is listed or sent.
 */
interface GrievanceCourtRecords
{
    /**
     * The user's own grievances past the draft stage, newest first, with the
     * number of PDFs each would bring.
     *
     * @return list<array{id: string, reference_number: ?string, subject: ?string, status: string, submitted_at: ?string, documents: int}>
     */
    public function filedBy(User $user, int $maxBytes, int $limit = 50): array;

    /**
     * Of the given ids, those that are the user's own submitted grievances.
     *
     * @param  list<string>  $grievanceIds
     * @return list<string>
     */
    public function ownIds(User $user, array $grievanceIds): array;

    /**
     * What court-ms keeps of each grievance (facts, issued letters as
     * responses, issued decisions) and the PDFs it brings, oldest first.
     *
     * @param  list<string>  $grievanceIds
     * @return array{snapshots: list<array<string, mixed>>, documents: list<array{name: string, contents: string, title: string, source: string}>}
     */
    public function courtFile(User $user, array $grievanceIds, int $maxBytes): array;
}
