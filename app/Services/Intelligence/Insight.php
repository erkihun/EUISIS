<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

/**
 * One insight: a titled statement of what changed, why it matters, the
 * evidence behind it and the recommended next step (brief §92–94).
 *
 * Labels travel as translation keys (`intelligence.insights.<key>.title`),
 * with `:name` parameters the page fills in, so the same insight reads in
 * either language. `confidence` is null for a deterministic insight; an
 * AI-derived or forecast insight states high / medium / low. Severity is
 * assigned by the rule that produced the insight, never by a model.
 */
final readonly class Insight
{
    public const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

    public const CONFIDENCES = ['high', 'medium', 'low'];

    /**
     * @param  array<string, string|int|float>  $titleParams
     * @param  array<string, string|int|float>  $summaryParams
     * @param  array{current: int|float|null, baseline: int|float|null, percent: ?float, unit: ?string, direction: 'up'|'down'|'flat'}|null  $change
     * @param  list<array{label: array<string, mixed>|string, value: string|int|float|null, href?: ?string}>  $evidence
     * @param  array<string, string|int|float>  $recommendationParams
     */
    public function __construct(
        public string $key,
        public string $severity,
        public string $title,
        public array $titleParams = [],
        public ?string $summary = null,
        public array $summaryParams = [],
        public ?array $change = null,
        public array $evidence = [],
        public ?string $recommendation = null,
        public array $recommendationParams = [],
        public ?string $confidence = null,
        public ?string $href = null,
        public ?string $source = null,
        public ?string $metric = null,
    ) {
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Unknown insight severity [{$severity}].");
        }
        if ($confidence !== null && ! in_array($confidence, self::CONFIDENCES, true)) {
            throw new \InvalidArgumentException("Unknown insight confidence [{$confidence}].");
        }
    }

    /** Sort weight: critical first. */
    public function weight(): int
    {
        return array_search($this->severity, self::SEVERITIES, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'title' => $this->title,
            'title_params' => (object) $this->titleParams,
            'summary' => $this->summary,
            'summary_params' => (object) $this->summaryParams,
            'change' => $this->change,
            'evidence' => $this->evidence,
            'recommendation' => $this->recommendation,
            'recommendation_params' => (object) $this->recommendationParams,
            'confidence' => $this->confidence,
            'href' => $this->href,
            'source' => $this->source,
            'metric' => $this->metric,
        ];
    }
}
