<?php

declare(strict_types=1);

namespace Modules\TipOffs\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Feedback\Contracts\PublicSubmissionTypes;
use Modules\TipOffs\Application\Services\TipOffSubmissionType;
use Modules\TipOffs\Domain\Models\TipOff;

/**
 * The Tip-offs module (ADR 0032): registers its submission type with the
 * public intake and its reference collision check. Its dashboard route is
 * in routes/web.php with the other public-submission pages.
 */
final class TipOffsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(PublicSubmissionTypes::class, fn (PublicSubmissionTypes $types) => $types->register(new TipOffSubmissionType));
    }

    public function boot(): void
    {
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::PublicTipOff, TipOff::class, 'reference_number');
    }
}
