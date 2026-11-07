<?php

declare(strict_types=1);

namespace Modules\PublicComplaints\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Feedback\Contracts\PublicSubmissionTypes;
use Modules\PublicComplaints\Application\Services\ComplaintSubmissionType;
use Modules\PublicComplaints\Domain\Models\PublicComplaint;

/**
 * The Public complaints module (ADR 0032): registers its submission type with
 * the public intake and its reference collision check. Its dashboard route
 * is in routes/web.php with the other public-submission pages.
 */
final class PublicComplaintsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(PublicSubmissionTypes::class, fn (PublicSubmissionTypes $types) => $types->register(new ComplaintSubmissionType));
    }

    public function boot(): void
    {
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::PublicServiceComplaint, PublicComplaint::class, 'reference_number');
    }
}
