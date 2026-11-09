<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Contracts;

use App\Services\Intelligence\Insight;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationPanel;

/**
 * What one Intelligence area computes for a viewer: the panels (figures,
 * charts, tables, honest notices) and the insights (what changed, why it
 * matters, the evidence, the recommended next step). Both read only the
 * context, so a cache key built from it is complete, and both are computed
 * on the server inside the viewer's scope.
 */
interface AreaAnalysis
{
    /** @return array<string, callable(): SituationPanel> */
    public function panels(SituationContext $context): array;

    /**
     * Insights derived from the same evidence the panels show. Deterministic:
     * every figure in an insight can be pointed at in a panel.
     *
     * @param  list<array<string, mixed>>  $panels  The panels already computed, as arrays.
     * @return list<Insight>
     */
    public function insights(SituationContext $context, array $panels): array;
}
