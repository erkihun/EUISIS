<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Services\Intelligence\Contracts\AreaAnalysis;
use App\Services\SituationRoom\Analytics\SituationAnalytics;
use App\Services\SituationRoom\SituationContext;
use Illuminate\Support\Facades\Lang;

/**
 * Base class of every Intelligence area analysis. It is a Situation Room
 * analytics class (scope-confined queries, roll-ups, trends, small-cell
 * protection, panel shortcuts) whose words come from the Intelligence
 * dictionary (lang/{en,am}/intelligence*.php, prefix `intelligence.`), plus
 * an insight builder. Subclasses add panels and insights; nothing here reads
 * a model row or a person.
 */
abstract class IntelligenceAnalysis extends SituationAnalytics implements AreaAnalysis
{
    public const PREFIX = 'intelligence.';

    /** {@inheritDoc} */
    public function insights(SituationContext $context, array $panels): array
    {
        return [];
    }

    protected function title(string $key): string
    {
        return self::PREFIX."panels.{$key}.title";
    }

    protected function hint(string $key): string
    {
        return self::PREFIX."panels.{$key}.description";
    }

    /** @param  array<string, mixed>  $extra */
    protected function kpi(string $key, int|float|null $value, array $extra = []): array
    {
        $definition = null;
        foreach (['intelligence', 'intelligence_system', 'intelligence_business'] as $file) {
            if (Lang::has("{$file}.kpiHint.{$key}", 'en')) {
                $definition = self::PREFIX."kpiHint.{$key}";
                break;
            }
        }

        return ['key' => $key, 'label' => self::PREFIX."kpi.{$key}", 'definition' => $definition, 'value' => $value] + $extra;
    }

    /** A source label key of the Intelligence dictionary. */
    protected function source(string $key): string
    {
        return self::PREFIX."sources.{$key}";
    }

    /**
     * An insight whose title, summary and recommendation follow its key.
     *
     * @param  array<string, string|int|float>  $params  Shared by title, summary and recommendation.
     * @param  list<array{label: array<string, mixed>|string, value: string|int|float|null, href?: ?string}>  $evidence
     * @param  array{current: int|float|null, baseline: int|float|null, percent: ?float, unit: ?string, direction: 'up'|'down'|'flat'}|null  $change
     */
    protected function insight(
        string $key,
        string $severity,
        array $params = [],
        array $evidence = [],
        ?array $change = null,
        ?string $confidence = null,
        ?string $href = null,
        ?string $source = null,
        ?string $metric = null,
        bool $recommendation = true,
    ): Insight {
        return new Insight(
            key: $key,
            severity: $severity,
            title: self::PREFIX."insights.{$key}.title",
            titleParams: $params,
            summary: self::PREFIX."insights.{$key}.summary",
            summaryParams: $params,
            change: $change,
            evidence: $evidence,
            recommendation: $recommendation ? self::PREFIX."insights.{$key}.recommendation" : null,
            recommendationParams: $params,
            confidence: $confidence,
            href: $href,
            source: $source,
            metric: $metric,
        );
    }

    /**
     * The change between a current and a baseline value, for an insight.
     *
     * @return array{current: int|float|null, baseline: int|float|null, percent: ?float, unit: ?string, direction: 'up'|'down'|'flat'}
     */
    protected function change(int|float|null $current, int|float|null $baseline, ?string $unit = null): array
    {
        $percent = ($baseline !== null && $baseline > 0 && $current !== null) ? round(100 * ($current - $baseline) / $baseline, 1) : null;
        $direction = $current === null || $baseline === null || $current == $baseline ? 'flat' : ($current > $baseline ? 'up' : 'down');

        return ['current' => $current, 'baseline' => $baseline, 'percent' => $percent, 'unit' => $unit, 'direction' => $direction];
    }

    /** One evidence row. @return array{label: array<string, mixed>|string, value: string|int|float|null, href: ?string} */
    protected function evidence(string $labelKey, string|int|float|null $value, ?string $href = null): array
    {
        return ['label' => ['key' => self::PREFIX."evidence.{$labelKey}"], 'value' => $value, 'href' => $href];
    }
}
