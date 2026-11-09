<?php

declare(strict_types=1);

namespace Modules\ServiceFeedback\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Feedback\Contracts\PublicSubmissionTypes;
use Modules\ServiceFeedback\Application\Services\ServiceFeedbackSubmissionType;
use Modules\ServiceFeedback\Domain\Models\ServiceFeedbackSubmission;

/**
 * The Service feedback module (ADR 0032): registers its submission type with
 * the public intake and its reference collision check.
 */
final class ServiceFeedbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(PublicSubmissionTypes::class, fn (PublicSubmissionTypes $types) => $types->register(new ServiceFeedbackSubmissionType));
    }

    public function boot(): void
    {
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::PublicServiceFeedback, ServiceFeedbackSubmission::class, 'reference_number');
    }
}
