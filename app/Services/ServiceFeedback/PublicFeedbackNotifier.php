<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackHandlerType;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\PublicFeedbackAssignment;
use App\Models\User;
use App\Notifications\PublicFeedbackAcknowledgement;
use App\Notifications\PublicFeedbackNotification;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Notices about public submissions (brief §88–89), sent after the
 * surrounding transaction commits through the platform's channels. Staff
 * notices carry a reference and a link, never the text or the submitter;
 * the submitter's acknowledgement carries a reference and a time, never a
 * link. A failing provider is logged and never fails the submission.
 */
final class PublicFeedbackNotifier
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly SystemSettingsService $settings,
        private readonly PublicFeedbackSettings $policy,
    ) {}

    /** Brief §46: a submission without a routing rule is brought to the routing administrators. */
    public function unrouted(EmployeeServiceFeedback $submission): void
    {
        $this->send($this->routingAdministrators($submission), 'unrouted', $submission);
    }

    /** Brief §88 "Assignment" and "Reassignment": the people behind the new handler. */
    public function assigned(EmployeeServiceFeedback $submission, PublicFeedbackAssignment $assignment, ?User $actor): void
    {
        $this->send($this->without($this->handlerUsers($submission, $assignment), $actor), 'assigned', $submission);
    }

    /** Brief §88 "Resolution": whoever handed the submission over, and its handlers, other than the one acting. */
    public function resolved(EmployeeServiceFeedback $submission, ?User $actor): void
    {
        $assignment = $submission->currentAssignment()->first() ?? $submission->assignments()->latest('assigned_at')->first();
        $users = $assignment === null ? [] : $this->handlerUsers($submission, $assignment);

        if ($assignment?->assigned_by !== null) {
            $assigner = User::query()->find($assignment->assigned_by);
            if ($assigner !== null && $this->mayHandle($assigner, $submission)) {
                $users[] = $assigner;
            }
        }

        $this->send($this->without($users, $actor), 'resolved', $submission);
    }

    /**
     * Brief §89: an asynchronous receipt to a submitter who left an e-mail,
     * when the policy allows. A phone number gets nothing: SMS is undecided.
     */
    public function acknowledge(EmployeeServiceFeedback $submission, string $locale): void
    {
        $contact = trim((string) $submission->client_contact);

        if (! $this->policy->acknowledgementEnabled() || $contact === '' || filter_var($contact, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $notification = new PublicFeedbackAcknowledgement(
            $submission->type->value,
            $submission->reference_number,
            ($submission->submitted_at ?? now())->toDateTimeString(),
            in_array($locale, ['en', 'am'], true) ? $locale : 'en',
        );

        DB::afterCommit(function () use ($contact, $notification): void {
            try {
                Notification::route('mail', $contact)->notify($notification);
            } catch (Throwable $exception) {
                Log::warning('Public feedback acknowledgement failed', ['exception' => $exception->getMessage()]);
            }
        });
    }

    /** @return list<User> */
    public function routingAdministrators(EmployeeServiceFeedback $submission): array
    {
        return $this->usersWithPermission(PublicFeedbackRoutingService::PERMISSION, $submission);
    }

    /**
     * The people a handler stands for: the named user, every user holding the
     * role, or every user whose employee record sits in the unit — in each
     * case only those who may see the submission (§56, §54).
     *
     * @return list<User>
     */
    public function handlerUsers(EmployeeServiceFeedback $submission, PublicFeedbackAssignment $assignment): array
    {
        $users = match ($assignment->handler_type) {
            PublicFeedbackHandlerType::User => User::query()->whereKey($assignment->handler_id)->where('status', 'active')->get(),
            PublicFeedbackHandlerType::Role => User::query()->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->whereKey($assignment->handler_id))->get(),
            PublicFeedbackHandlerType::OrganizationUnit => User::query()->where('status', 'active')
                ->whereHas('employee.currentAssignment', fn ($q) => $q->where('organization_unit_id', $assignment->handler_id))->get(),
        };

        return $users
            ->filter(fn (User $user): bool => $user->hasAnyPermission([$this->viewPermission($submission)]) && $this->mayHandle($user, $submission))
            ->values()
            ->all();
    }

    /** @return list<User> */
    private function usersWithPermission(string $permission, EmployeeServiceFeedback $submission): array
    {
        try {
            $users = User::query()->permission($permission)->where('status', 'active')->get();
        } catch (PermissionDoesNotExist) {
            return [];
        }

        return $users->filter(fn (User $user): bool => $this->mayHandle($user, $submission))->values()->all();
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

    private function viewPermission(EmployeeServiceFeedback $submission): string
    {
        return $submission->type === ServiceFeedbackType::Complaint ? 'service_feedback.complaints.view' : 'service_feedback.view';
    }

    /**
     * @param  list<User>  $users
     * @return list<User>
     */
    private function without(array $users, ?User $actor): array
    {
        $seen = [];
        $result = [];
        foreach ($users as $user) {
            $key = (int) $user->getKey();
            if (($actor !== null && $key === (int) $actor->getKey()) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $user;
        }

        return $result;
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
                    $channels = $this->channels($user);
                    if ($channels !== []) {
                        $user->notify(new PublicFeedbackNotification($kind, $type, $reference, $url, $channels));
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Public feedback notification failed', ['kind' => $kind, 'exception' => $exception->getMessage()]);
            }
        });
    }

    /** The platform-wide notification channels, as the grievance module reads them. @return list<string> */
    private function channels(User $user): array
    {
        $group = SystemSettingsRegistry::GROUP_NOTIFICATIONS;
        $channels = [];
        if (filter_var($this->settings->get($group, 'database_notifications_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'database';
        }
        if (filter_var($this->settings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN) && $user->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
