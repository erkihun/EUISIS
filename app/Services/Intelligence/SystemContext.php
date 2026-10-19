<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Models\User;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationFilters;
use App\Services\SituationRoom\SituationScope;
use App\Services\SituationRoom\SituationSettings;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * The city-wide context scheduled jobs compute with (alerts, forecast
 * snapshots): an unrestricted scope, the default period, and the acting
 * account the analyses check grants against. There is no system account in
 * the platform, so the first active Super Admin stands in; without one the
 * scheduled business analyses do not run and say so.
 */
final class SystemContext
{
    public function __construct(private readonly SituationSettings $settings) {}

    public function actor(): ?User
    {
        try {
            return User::query()->role('Super Admin')->where('status', 'active')->orderBy('id')->first();
        } catch (RoleDoesNotExist) {
            return null;
        }
    }

    public function cityWide(?User $actor = null, string $period = 'month'): ?SituationContext
    {
        $actor ??= $this->actor();
        if ($actor === null) {
            return null;
        }

        return new SituationContext(
            $actor,
            new SituationScope(null, null, true),
            SituationFilters::fromRequest(Request::create('/intelligence', 'GET', ['period' => $period]), $period),
            $this->settings->topN(),
        );
    }
}
