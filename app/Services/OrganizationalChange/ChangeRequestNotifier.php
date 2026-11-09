<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Models\OrganizationalChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Queues workflow notifications.
 *
 * IMPORTANT, and deliberately not papered over: this project has no
 * notification delivery system yet. There is no in-app notification model, no
 * mail/SMS/Telegram dispatcher and no queue worker for it — only the
 * `notifications_foundation` table created by the foundation migration.
 *
 * So this class writes rows there with status "pending" and never claims to
 * have delivered anything. When a real delivery system is built, it reads
 * these rows; nothing else in the workflow has to change. Recipients are
 * resolved by permission and organization scope at the moment of the event.
 */
final readonly class ChangeRequestNotifier
{
    private const TABLE = 'notifications_foundation';

    public function __construct(private ChangeRequestScopeService $scope) {}

    public function submitted(OrganizationalChangeRequest $request): void
    {
        $this->queue($request, 'submitted', $this->reviewersFor($request));
    }

    public function resubmitted(OrganizationalChangeRequest $request): void
    {
        $recipients = $this->reviewersFor($request);

        // Prefer the reviewer who asked for the correction.
        if ($request->reviewed_by !== null) {
            $recipients = array_values(array_unique([...$recipients, (int) $request->reviewed_by]));
        }

        $this->queue($request, 'resubmitted', $recipients);
    }

    public function correctionRequested(OrganizationalChangeRequest $request, ?string $comment = null): void
    {
        $this->queue($request, 'correction_requested', [(int) $request->requested_by], ['comment' => $comment]);
    }

    public function approved(OrganizationalChangeRequest $request): void
    {
        $this->queue($request, 'approved', [(int) $request->requested_by]);
        $this->queue($request, 'approved_for_implementation', $this->implementersFor($request));
    }

    public function rejected(OrganizationalChangeRequest $request, ?string $comment = null): void
    {
        $this->queue($request, 'rejected', [(int) $request->requested_by], ['comment' => $comment]);
    }

    public function implementationBlocked(OrganizationalChangeRequest $request, array $conflicts): void
    {
        $recipients = array_values(array_unique([
            ...$this->implementersFor($request),
            ...$this->reviewersFor($request),
        ]));

        $this->queue($request, 'implementation_blocked', $recipients, ['conflicts' => $conflicts]);
    }

    public function implemented(OrganizationalChangeRequest $request): void
    {
        $recipients = [(int) $request->requested_by];

        if ($request->approved_by !== null) {
            $recipients[] = (int) $request->approved_by;
        }

        $this->queue($request, 'implemented', array_values(array_unique($recipients)));
    }

    public function completed(OrganizationalChangeRequest $request): void
    {
        $recipients = [(int) $request->requested_by];

        if ($request->approved_by !== null) {
            $recipients[] = (int) $request->approved_by;
        }

        $this->queue($request, 'completed', array_values(array_unique($recipients)));
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $extra
     */
    private function queue(OrganizationalChangeRequest $request, string $event, array $userIds, array $extra = []): void
    {
        if ($userIds === []) {
            return;
        }

        $now = now();

        $rows = array_map(static fn (int $userId): array => [
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'channel' => 'database',
            // Nothing has been sent. A delivery system will pick these up.
            'status' => 'pending',
            'payload' => json_encode(array_merge([
                'type' => 'organizational_change_request',
                'event' => $event,
                'request_id' => (string) $request->getKey(),
                'request_no' => $request->request_no,
                'request_type' => $request->request_type->value,
                'status' => $request->status->value,
                'organization_id' => $request->organization_id,
            ], $extra), JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds);

        DB::table(self::TABLE)->insert($rows);
    }

    /**
     * Users who may review this request: they hold the review permission and
     * the request's organization is inside their scope.
     *
     * @return array<int, int>
     */
    private function reviewersFor(OrganizationalChangeRequest $request): array
    {
        return $this->usersWithPermissionInScope($request, 'organizational-change-requests.review');
    }

    /** @return array<int, int> */
    private function implementersFor(OrganizationalChangeRequest $request): array
    {
        if ($request->implementation_assigned_to !== null) {
            return [(int) $request->implementation_assigned_to];
        }

        return $this->usersWithPermissionInScope($request, 'organizational-change-requests.implement');
    }

    /** @return array<int, int> */
    private function usersWithPermissionInScope(OrganizationalChangeRequest $request, string $permission): array
    {
        return User::query()
            ->where('id', '!=', $request->requested_by)
            ->get()
            ->filter(fn (User $user): bool => $user->can($permission)
                && $this->scope->canAccessOrganization($user, $request->organization_id))
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->values()
            ->all();
    }
}
