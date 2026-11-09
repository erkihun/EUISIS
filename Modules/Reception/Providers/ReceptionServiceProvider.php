<?php

declare(strict_types=1);

namespace Modules\Reception\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Reception\Domain\Models\ReceptionAppointment;
use Modules\Reception\Domain\Models\ReceptionCounter;
use Modules\Reception\Domain\Models\ReceptionDesk;
use Modules\Reception\Domain\Models\ReceptionQueueTicket;
use Modules\Reception\Domain\Models\ReceptionReferral;
use Modules\Reception\Domain\Models\ReceptionVisit;
use Modules\Reception\Domain\Models\ReceptionVisitor;

final class ReceptionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Numbers its records take from code rules; a number already in use is skipped.
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::ReceptionVisit, ReceptionVisit::class, 'reference');
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::ReceptionAppointment, ReceptionAppointment::class, 'reference');
        Relation::morphMap([
            ReceptionDesk::class => ReceptionDesk::class, ReceptionCounter::class => ReceptionCounter::class,
            ReceptionVisitor::class => ReceptionVisitor::class, ReceptionVisit::class => ReceptionVisit::class,
            ReceptionQueueTicket::class => ReceptionQueueTicket::class, ReceptionAppointment::class => ReceptionAppointment::class,
            ReceptionReferral::class => ReceptionReferral::class,
        ]);

        CodeRuleTargetRegistry::registerTarget(CodeRuleEntityType::ReceptionDesk, [
            'model' => ReceptionDesk::class, 'table' => 'reception_desks', 'code_column' => 'code',
            'label' => 'Reception Desk Code', 'immutable_after_create' => true,
        ]);
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::ReceptionDesk, ReceptionDesk::class, 'code');

        Route::middleware(['web', 'auth', 'verified', 'mfa', 'force.password', 'admin.access'])
            ->group(base_path('routes/reception.php'));
    }
}
