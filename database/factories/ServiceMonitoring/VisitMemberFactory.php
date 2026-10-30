<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\VisitMember;

/** @extends Factory<VisitMember> */
class VisitMemberFactory extends Factory
{
    protected $model = VisitMember::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'visit_id' => MonitoringVisit::factory(),
            'user_id' => User::factory(),
        ];
    }
}
