<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Models\EmployeeServiceFeedback;
use App\Models\User;
use App\Notifications\PublicFeedbackNotification;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * In-app notices about public submissions, sent after the surrounding
 * transaction commits. Notices carry a reference and a link, never the
 * submission text or the submitter's details.
 */
final class PublicFeedbackNotifier
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    /** Brief §46: a submission without a routing rule is brought to the routing administrators. */
    public function unrouted(EmployeeServiceFeedback $submission): void
    {
        $this->send($this->routingAdministrators($submission), 'unrouted', $submission);
    }

    /** @return list<User> */
    public function routingAdministrators(EmployeeServiceFeedback $submission): array
    {
        try {
            $users = User::query()->permission(PublicFeedbackRoutingService::PERMISSION)->where('status', 'active')->get();
        } catch (PermissionDoesNotExist) {
            return [];
        }

        return $users
            ->filter(fn (User $user): bool => $this->mayHandle($user, $submission))
            ->values()
            ->all();
    }

    /** In scope for the submission's organization and never its target employee (§54). */
    private function mayHandle(User $user, EmployeeServiceFeedback $submission): bool
    {
        if ($user->employee_id !== null && $submission->employee_id !== null && (string) $user->employee_id === (string) $submission->employee_id) {
            return false;
        }

        return $this->scope->isUnrestricted($user)
            || ($submission->organization_id !== null && $this->scope->canAccessOrganization($user, $submission->organization_id));
    }

    /** @param  list<User>  $users */
    private function send(array $users, string $kind, EmployeeServiceFeedback $submission): void
    {
        if ($users === []) {
            return;
        }

        $reference = $submission->reference_number ?? strtoupper(substr((string) $submission->getKey(), 0, 8));
        $type = $submission->type->value;
        $url = route('service-submissions.show', ['type' => $type, 'submission' => $submission->getKey()], false);
        $ids = array_map(fn (User $user) => $user->getKey(), $users);

        DB::afterCommit(function () use ($ids, $kind, $type, $reference, $url): void {
            try {
                foreach (User::query()->whereKey($ids)->get() as $user) {
                    $user->notify(new PublicFeedbackNotification($kind, $type, $reference, $url));
                }
            } catch (Throwable $exception) {
                Log::warning('Public feedback notification failed', ['kind' => $kind, 'exception' => $exception->getMessage()]);
            }
        });
    }
}
