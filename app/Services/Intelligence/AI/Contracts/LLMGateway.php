<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI\Contracts;

use App\Services\Intelligence\AI\AiRequest;
use App\Services\Intelligence\AI\AiResult;

/**
 * The one door to a language model (brief §23, §59, §80–82). Business code
 * never talks to a provider: it builds an AiRequest from a versioned prompt
 * and a compact, redacted evidence bundle, and gets an AiResult that is
 * already validated, recorded (intelligence_ai_runs) and safe to render as
 * untrusted text. When AI is off, unconfigured, capped or tripped, the result
 * says `unavailable` and the caller falls back to its deterministic output.
 */
interface LLMGateway
{
    public function complete(AiRequest $request): AiResult;

    /** Whether a call could succeed right now (enabled, configured, breaker closed, under the daily cap). */
    public function available(): bool;

    /**
     * What Governance shows: never a secret.
     *
     * @return array{enabled: bool, provider: string, model: ?string, configured: bool, breaker_open: bool, calls_today: int, daily_cap: int, outbound_classes: list<string>}
     */
    public function status(): array;
}
