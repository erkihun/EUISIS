<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IntelligenceAiRun;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\AI\Providers\AnthropicProvider;
use App\Services\Intelligence\AI\Providers\OpenAiCompatibleProvider;
use App\Services\Intelligence\AI\Providers\Provider;
use App\Services\Intelligence\IntelligenceSettings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The gateway (brief §59, §80–82, §116–118): Authorize → Minimize → Redact →
 * Classify → Send → Validate → Record. A provider failure never reaches the
 * caller as an exception; it is an AiResult that says why, and a cache-backed
 * circuit breaker keeps the platform from retrying a dead provider on every
 * page. A daily request cap bounds cost. Every attempt is recorded.
 */
final class AiGateway implements LLMGateway
{
    private const BREAKER_KEY = 'intel:ai:breaker';

    private const FAILURES_KEY = 'intel:ai:failures';

    private const CALLS_KEY = 'intel:ai:calls:';

    public function __construct(
        private readonly IntelligenceSettings $settings,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function available(): bool
    {
        return $this->settings->aiEnabled() && $this->provider() !== null && ! $this->breakerOpen() && $this->callsToday() < $this->dailyCap();
    }

    public function status(): array
    {
        $provider = $this->provider();

        return [
            'enabled' => $this->settings->aiEnabled(),
            'provider' => $this->settings->aiProvider(),
            'model' => $provider?->model(),
            'configured' => $provider !== null,
            'breaker_open' => $this->breakerOpen(),
            'calls_today' => $this->callsToday(),
            'daily_cap' => $this->dailyCap(),
            'outbound_classes' => $this->settings->outboundDataClasses(),
        ];
    }

    public function complete(AiRequest $request): AiResult
    {
        $template = PromptRegistry::get($request->promptKey);
        $base = ['feature' => $request->feature, 'prompt_key' => $request->promptKey, 'prompt_version' => $template['version'], 'provider' => $this->settings->aiProvider()];

        if (! $this->settings->aiEnabled()) {
            return $this->record($request, $base, AiResult::unavailable(AiResult::UNAVAILABLE, 'ai_disabled'));
        }
        $provider = $this->provider();
        if ($provider === null) {
            return $this->record($request, $base, AiResult::unavailable(AiResult::UNAVAILABLE, 'provider_not_configured'));
        }
        // Classify: a request may only carry data classes the settings allow outbound, and the prompt declares.
        $disallowed = array_diff($request->dataClasses, $this->settings->outboundDataClasses());
        $undeclared = array_diff($request->dataClasses, $template['data_classes']);
        if ($disallowed !== [] || $undeclared !== []) {
            return $this->record($request, $base, AiResult::unavailable(AiResult::REFUSED, 'data_class_not_allowed:'.implode(',', [...$disallowed, ...$undeclared])));
        }
        if ($this->breakerOpen()) {
            return $this->record($request, $base, AiResult::unavailable(AiResult::UNAVAILABLE, 'circuit_open'));
        }
        if ($this->callsToday() >= $this->dailyCap()) {
            return $this->record($request, $base, AiResult::unavailable(AiResult::REFUSED, 'daily_cap_reached'));
        }

        // Minimize + redact.
        $input = Redactor::truncate(Redactor::text($request->input), $this->settings->aiMaxInputChars());
        $user = PromptRegistry::render($request->promptKey, $input, ['locale' => $request->locale ?? 'en', ...$request->variables]);
        $maxTokens = min($template['max_output_tokens'], max(100, (int) config('intelligence.ai.max_output_tokens', 1200)));

        $started = microtime(true);
        try {
            Cache::increment(self::CALLS_KEY.now()->toDateString());
            $raw = $provider->chat($template['system'], $user, $maxTokens, $this->settings->aiTimeoutSeconds());
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $this->recordSuccess();
        } catch (Throwable $e) {
            $this->recordFailure();

            return $this->record($request, $base + ['model' => $provider->model()], new AiResult(ok: false, provider: $this->settings->aiProvider(), model: $provider->model(), promptVersion: $template['version'], reason: AiResult::FAILED, error: Redactor::text(mb_substr($e->getMessage(), 0, 500)), inputChars: mb_strlen($input), durationMs: (int) round((microtime(true) - $started) * 1000)));
        }

        // Validate.
        $json = null;
        $text = AiResponseValidator::cleanText($raw['text']);
        if ($request->schema !== null || $template['schema'] !== []) {
            $validated = AiResponseValidator::validate(AiResponseValidator::extractJson($raw['text']), $request->schema ?? $template['schema']);
            if (! $validated['ok']) {
                return $this->record($request, $base + ['model' => $provider->model()], new AiResult(ok: false, provider: $this->settings->aiProvider(), model: $provider->model(), promptVersion: $template['version'], reason: AiResult::INVALID, error: $validated['error'], inputChars: mb_strlen($input), outputTokens: $raw['output_tokens'], durationMs: $durationMs));
            }
            $json = $validated['data'];
        }

        return $this->record($request, $base + ['model' => $provider->model()], new AiResult(
            ok: true, text: $text, json: $json, provider: $this->settings->aiProvider(), model: $provider->model(), promptVersion: $template['version'],
            inputChars: mb_strlen($input), outputTokens: $raw['output_tokens'], durationMs: $durationMs,
        ));
    }

    private function provider(): ?Provider
    {
        $provider = match ($this->settings->aiProvider()) {
            'openai_compatible' => new OpenAiCompatibleProvider((array) config('intelligence.ai.openai_compatible', []), $this->settings->aiModel()),
            'anthropic' => new AnthropicProvider((array) config('intelligence.ai.anthropic', []), $this->settings->aiModel()),
            default => null,
        };

        return $provider?->configured() ? $provider : null;
    }

    private function breakerOpen(): bool
    {
        return (bool) Cache::get(self::BREAKER_KEY, false);
    }

    private function recordFailure(): void
    {
        $failures = (int) Cache::increment(self::FAILURES_KEY);
        if ($failures >= max(1, (int) config('intelligence.ai.breaker_failures', 3))) {
            Cache::put(self::BREAKER_KEY, true, now()->addSeconds(max(30, (int) config('intelligence.ai.breaker_open_seconds', 300))));
            Cache::forget(self::FAILURES_KEY);
        }
    }

    private function recordSuccess(): void
    {
        Cache::forget(self::FAILURES_KEY);
    }

    private function callsToday(): int
    {
        return (int) Cache::get(self::CALLS_KEY.now()->toDateString(), 0);
    }

    private function dailyCap(): int
    {
        return max(1, (int) config('intelligence.ai.daily_request_cap', 500));
    }

    /** @param  array<string, mixed>  $base */
    private function record(AiRequest $request, array $base, AiResult $result): AiResult
    {
        try {
            $run = IntelligenceAiRun::query()->create([
                ...$base,
                'model' => $base['model'] ?? null,
                'status' => $result->ok ? 'ok' : ($result->reason ?? 'failed'),
                'triggered_by_user_id' => $request->triggeredByUserId,
                'subject_type' => $request->subjectType,
                'subject_id' => $request->subjectId,
                'input_summary' => ['data_classes' => $request->dataClasses, 'chars' => $result->inputChars ?: mb_strlen($request->input), 'variables' => array_keys($request->variables), 'locale' => $request->locale],
                'output' => $result->ok ? ($result->json ?? ['text' => $result->text]) : null,
                'error' => $result->error,
                'input_chars' => $result->inputChars ?: mb_strlen($request->input),
                'output_tokens' => $result->outputTokens,
                'duration_ms' => $result->durationMs,
                'created_at' => now(),
            ]);
            if ($result->ok) {
                $this->audit->execute(AuditEventType::IntelligenceAiRunRecorded, null, $run, newValues: ['feature' => $request->feature, 'prompt' => $request->promptKey.'@'.$base['prompt_version'], 'provider' => $base['provider']]);
            }

            return new AiResult(
                ok: $result->ok, text: $result->text, json: $result->json, provider: $result->provider ?? $base['provider'], model: $result->model ?? ($base['model'] ?? null),
                promptVersion: $base['prompt_version'], runId: $run->id, reason: $result->reason, error: $result->error, inputChars: $result->inputChars, outputTokens: $result->outputTokens, durationMs: $result->durationMs,
            );
        } catch (Throwable $e) {
            report($e);

            return $result;
        }
    }
}
