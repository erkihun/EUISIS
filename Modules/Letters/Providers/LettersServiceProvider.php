<?php

declare(strict_types=1);

namespace Modules\Letters\Providers;

use App\Support\NotificationPresenter;
use App\Support\Rbac\RoleBypassExemptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Application\Policies\LetterAttachmentPolicy;
use Modules\Letters\Application\Policies\LetterNumberingRulePolicy;
use Modules\Letters\Application\Policies\LetterPolicy;
use Modules\Letters\Application\Policies\LetterRegistrationPolicy;
use Modules\Letters\Application\Policies\LetterRoutingPolicy;
use Modules\Letters\Application\Policies\LetterTemplatePolicy;
use Modules\Letters\Application\Queries\LetterIssuerService;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Contracts\LetterIssuer;
use Modules\Letters\Contracts\LetterLinks;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterAttachment;
use Modules\Letters\Domain\Models\LetterNumberingRule;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;
use Modules\Letters\Domain\Models\LetterTemplate;
use Modules\Letters\Infrastructure\Console\SendLetterRemindersCommand;

/**
 * Everything the application needs to know about Letters (ADR 0037): its
 * public contracts, policies and notice renderer. Routes live in
 * routes/letters.php.
 */
final class LettersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LetterIssuer::class, LetterIssuerService::class);
        $this->app->bind(LetterLinks::class, LetterIssuerService::class);
        // Per request: cached scope answers never leak between users.
        $this->app->scoped(LettersAccess::class);
    }

    public function boot(): void
    {
        // Policy discovery only looks in App\Policies.
        Gate::policy(Letter::class, LetterPolicy::class);
        Gate::policy(LetterRegistration::class, LetterRegistrationPolicy::class);
        Gate::policy(LetterRouting::class, LetterRoutingPolicy::class);
        Gate::policy(LetterAttachment::class, LetterAttachmentPolicy::class);
        Gate::policy(LetterTemplate::class, LetterTemplatePolicy::class);
        Gate::policy(LetterNumberingRule::class, LetterNumberingRulePolicy::class);

        // Every rule depends on the record's state (dispatched, pending receipt, open
        // routing) as much as on the role, so the Super Admin bypass must not skip
        // them: the policies decide. Permissions and city-wide reach still come from
        // the role (OrganizationScopeService::isUnrestricted).
        RoleBypassExemptions::register([
            Letter::class, LetterRegistration::class, LetterRouting::class, LetterAttachment::class, LetterTemplate::class, LetterNumberingRule::class,
        ]);

        NotificationPresenter::registerRenderer('letters', LetterNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SendLetterRemindersCommand::class]);
        }
    }
}
