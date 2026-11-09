<?php

declare(strict_types=1);

namespace Modules\Discipline\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Modules\Discipline\Application\Queries\DisciplineCasesQuery;
use Modules\Discipline\Contracts\DisciplineCases;
use Modules\Discipline\Domain\Models\DisciplinaryAppeal;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Modules\Discipline\Domain\Models\DisciplinaryCaseMember;
use Modules\Discipline\Domain\Models\DisciplinaryCharge;
use Modules\Discipline\Domain\Models\DisciplinaryDecision;
use Modules\Discipline\Domain\Models\DisciplinaryEvidence;
use Modules\Discipline\Domain\Models\DisciplinaryRecommendation;
use Modules\Discipline\Domain\Models\DisciplinaryRecommendationVote;
use Modules\Discipline\Domain\Models\DisciplinaryResponse;
use Modules\Discipline\Domain\Models\DisciplinaryStatusHistory;
use Modules\Discipline\Domain\Models\DisciplinarySuspension;
use Modules\Discipline\Domain\Models\DisciplinaryWitness;

/**
 * Everything the application needs to know about the Discipline module
 * (ADR 0017): its public contract, its case-number collision check and the
 * names its records keep in the database. Its routes stay in
 * routes/discipline.php, which names its controllers.
 */
final class DisciplineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DisciplineCases::class, DisciplineCasesQuery::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, deadlines, notifications).
        Relation::morphMap([
            'App\Models\DisciplinaryAppeal' => DisciplinaryAppeal::class,
            'App\Models\DisciplinaryCase' => DisciplinaryCase::class,
            'App\Models\DisciplinaryCaseMember' => DisciplinaryCaseMember::class,
            'App\Models\DisciplinaryCharge' => DisciplinaryCharge::class,
            'App\Models\DisciplinaryDecision' => DisciplinaryDecision::class,
            'App\Models\DisciplinaryEvidence' => DisciplinaryEvidence::class,
            'App\Models\DisciplinaryRecommendation' => DisciplinaryRecommendation::class,
            'App\Models\DisciplinaryRecommendationVote' => DisciplinaryRecommendationVote::class,
            'App\Models\DisciplinaryResponse' => DisciplinaryResponse::class,
            'App\Models\DisciplinaryStatusHistory' => DisciplinaryStatusHistory::class,
            'App\Models\DisciplinarySuspension' => DisciplinarySuspension::class,
            'App\Models\DisciplinaryWitness' => DisciplinaryWitness::class,
        ]);

        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::DisciplinaryCase, DisciplinaryCase::class, 'case_number');
    }
}
