<?php

declare(strict_types=1);

namespace Modules\Communication\Providers;

use App\Backbone\Events\AssignmentChanged;
use App\Backbone\Events\EmployeeChanged;
use App\Models\User;
use App\Support\NotificationPresenter;
use App\Support\Rbac\RoleBypassExemptions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Application\Policies\AnnouncementPolicy;
use Modules\Communication\Application\Policies\ChannelPolicy;
use Modules\Communication\Application\Policies\CommunicationAccessRequestPolicy;
use Modules\Communication\Application\Policies\CommunicationReportPolicy;
use Modules\Communication\Application\Policies\CommunicationTaskPolicy;
use Modules\Communication\Application\Policies\ConversationPolicy;
use Modules\Communication\Application\Policies\MessageAttachmentPolicy;
use Modules\Communication\Application\Policies\MessagePolicy;
use Modules\Communication\Application\Queries\CommunicationTopBarQuery;
use Modules\Communication\Application\Services\AttachmentScanner;
use Modules\Communication\Application\Services\CommunicationMembershipSync;
use Modules\Communication\Application\Services\PendingAttachmentScanner;
use Modules\Communication\Contracts\CommunicationTopBar;
use Modules\Communication\Domain\Models\Announcement;
use Modules\Communication\Domain\Models\AnnouncementAttachment;
use Modules\Communication\Domain\Models\AnnouncementReceipt;
use Modules\Communication\Domain\Models\AnnouncementTarget;
use Modules\Communication\Domain\Models\Channel;
use Modules\Communication\Domain\Models\CommunicationAccessRequest;
use Modules\Communication\Domain\Models\CommunicationBlock;
use Modules\Communication\Domain\Models\CommunicationReport;
use Modules\Communication\Domain\Models\CommunicationTask;
use Modules\Communication\Domain\Models\CommunicationUserSetting;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\ConversationMember;
use Modules\Communication\Domain\Models\ConversationPin;
use Modules\Communication\Domain\Models\Message;
use Modules\Communication\Domain\Models\MessageAttachment;
use Modules\Communication\Domain\Models\MessageMention;
use Modules\Communication\Domain\Models\MessageReaction;
use Modules\Communication\Infrastructure\Console\ExpireAnnouncements;
use Modules\Communication\Infrastructure\Console\ExpireBreakGlassAccess;
use Modules\Communication\Infrastructure\Console\PruneExpiredCommunicationData;
use Modules\Communication\Infrastructure\Console\PublishScheduledAnnouncements;
use Modules\Communication\Infrastructure\Console\SyncCommunicationMemberships;

/**
 * Everything the application needs to know about the Communication module
 * (ADR 0017): its policies, the Core events it follows, its notification
 * renderer, rate limiters and commands, the records the Super Admin role never
 * opens by itself, and the names its records keep in the database. Its routes
 * stay in routes/communication.php and routes/channels.php, which name its
 * controllers, middleware and broadcast authorization.
 */
final class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Malware-scan hook: replace with a real scanner binding in production.
        $this->app->bindIf(AttachmentScanner::class, PendingAttachmentScanner::class);
        $this->app->scoped(CommunicationMembershipSync::class);
        $this->app->bind(CommunicationTopBar::class, CommunicationTopBarQuery::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, notifications, broadcasts).
        Relation::morphMap([
            'App\Models\Communication\Announcement' => Announcement::class,
            'App\Models\Communication\AnnouncementAttachment' => AnnouncementAttachment::class,
            'App\Models\Communication\AnnouncementReceipt' => AnnouncementReceipt::class,
            'App\Models\Communication\AnnouncementTarget' => AnnouncementTarget::class,
            'App\Models\Communication\Channel' => Channel::class,
            'App\Models\Communication\CommunicationAccessRequest' => CommunicationAccessRequest::class,
            'App\Models\Communication\CommunicationBlock' => CommunicationBlock::class,
            'App\Models\Communication\CommunicationReport' => CommunicationReport::class,
            'App\Models\Communication\CommunicationTask' => CommunicationTask::class,
            'App\Models\Communication\CommunicationUserSetting' => CommunicationUserSetting::class,
            'App\Models\Communication\Conversation' => Conversation::class,
            'App\Models\Communication\ConversationMember' => ConversationMember::class,
            'App\Models\Communication\ConversationPin' => ConversationPin::class,
            'App\Models\Communication\Message' => Message::class,
            'App\Models\Communication\MessageAttachment' => MessageAttachment::class,
            'App\Models\Communication\MessageMention' => MessageMention::class,
            'App\Models\Communication\MessageReaction' => MessageReaction::class,
        ]);

        // Policy discovery only looks in App\Policies.
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(Channel::class, ChannelPolicy::class);
        Gate::policy(CommunicationAccessRequest::class, CommunicationAccessRequestPolicy::class);
        Gate::policy(CommunicationReport::class, CommunicationReportPolicy::class);
        Gate::policy(CommunicationTask::class, CommunicationTaskPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(MessageAttachment::class, MessageAttachmentPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);

        // Private communication, reported content and investigation access are never
        // opened by role (docs/communication-module.md §Privacy): the policies decide.
        RoleBypassExemptions::register([
            Conversation::class,
            Message::class,
            MessageAttachment::class,
            CommunicationAccessRequest::class,
            CommunicationReport::class,
        ]);

        // Channel membership follows assignments and account status (master-backbone.md).
        Event::listen(AssignmentChanged::class, [CommunicationMembershipSync::class, 'onAssignmentChanged']);
        Event::listen(EmployeeChanged::class, [CommunicationMembershipSync::class, 'onEmployeeChanged']);
        User::saved(function (User $user): void {
            if ($user->employee_id !== null && $user->wasChanged('status')) {
                app(CommunicationMembershipSync::class)->queue($user->employee_id);
            }
        });

        NotificationPresenter::registerRenderer('communication', CommunicationNotification::class);

        // Abuse protection (docs/communication-module.md): per person, so one account
        // cannot flood a conversation, the directory or the moderators.
        $perUser = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
        RateLimiter::for('communication-send', fn (Request $request): array => [
            Limit::perMinute(40)->by('comm-send:'.$perUser($request)),
            Limit::perHour(600)->by('comm-send-h:'.$perUser($request)),
        ]);
        RateLimiter::for('communication-start', fn (Request $request): Limit => Limit::perMinute(20)->by('comm-start:'.$perUser($request)));
        RateLimiter::for('communication-upload', fn (Request $request): Limit => Limit::perMinute(20)->by('comm-upload:'.$perUser($request)));
        RateLimiter::for('communication-search', fn (Request $request): Limit => Limit::perMinute(40)->by('comm-search:'.$perUser($request)));
        RateLimiter::for('communication-react', fn (Request $request): Limit => Limit::perMinute(90)->by('comm-react:'.$perUser($request)));
        RateLimiter::for('communication-typing', fn (Request $request): Limit => Limit::perMinute(30)->by('comm-typing:'.$perUser($request)));
        RateLimiter::for('communication-group', fn (Request $request): Limit => Limit::perHour(15)->by('comm-group:'.$perUser($request)));
        RateLimiter::for('communication-channel', fn (Request $request): Limit => Limit::perHour(15)->by('comm-channel:'.$perUser($request)));
        RateLimiter::for('communication-report', fn (Request $request): Limit => Limit::perHour(20)->by('comm-report:'.$perUser($request)));

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireAnnouncements::class,
                ExpireBreakGlassAccess::class,
                PruneExpiredCommunicationData::class,
                PublishScheduledAnnouncements::class,
                SyncCommunicationMemberships::class,
            ]);
        }
    }
}
