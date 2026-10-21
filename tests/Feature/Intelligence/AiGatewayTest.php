<?php

declare(strict_types=1);

use App\Models\IntelligenceAiRun;
use App\Models\SystemSetting;
use App\Services\Intelligence\AI\AiRequest;
use App\Services\Intelligence\AI\AiResult;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * The AI gateway (docs/intelligence-ai-governance.md): off by default,
 * refuses data classes the settings do not allow, validates the provider's
 * JSON, trips a circuit breaker on repeated failures, caps requests per day,
 * and records every attempt without the raw input.
 */

function aiSetting(string $key, string $value): void
{
    SystemSetting::query()->updateOrCreate(['group' => 'intelligence', 'key' => $key], ['value' => $value, 'type' => 'string', 'label_en' => $key, 'label_am' => $key]);
    app(SystemSettingsService::class)->clearCache();
}

function enableOpenAi(): void
{
    aiSetting('ai_enabled', '1');
    aiSetting('ai_provider', 'openai_compatible');
    aiSetting('ai_outbound_data_classes', 'aggregates,structured_signals,redacted_logs');
    config(['intelligence.ai.openai_compatible' => ['base_url' => 'https://ai.example.test', 'api_key' => 'test-key', 'model' => 'test-model']]);
}

function rootCauseRequest(string $input = 'rule: http_error_rate; failures: 180'): AiRequest
{
    return new AiRequest(feature: 'root_cause_assistant', promptKey: 'incident_root_cause', input: $input, dataClasses: ['structured_signals'], subjectType: 'incident', subjectId: 'INC-1');
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
});

test('AI is off by default: the gateway is unavailable and records why', function (): void {
    $gateway = app(LLMGateway::class);
    expect($gateway->available())->toBeFalse()->and($gateway->status()['configured'])->toBeFalse();

    $result = $gateway->complete(rootCauseRequest());
    expect($result->ok)->toBeFalse()->and($result->reason)->toBe(AiResult::UNAVAILABLE)->and($result->error)->toBe('ai_disabled')
        ->and(IntelligenceAiRun::query()->where('status', 'unavailable')->count())->toBe(1);
});

test('a configured provider answers; the JSON is validated and the run recorded without the input text', function (): void {
    enableOpenAi();
    Http::fake(['https://ai.example.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'observed_symptom' => 'Errors on public feedback', 'likely_causes' => [['cause' => 'A failing query', 'confidence' => 'medium', 'why' => 'Timing matches']],
        'alternative_explanations' => ['Deployment'], 'recommended_next_checks' => ['Read the error id in the log'], 'suggested_areas_to_inspect' => ['app/Services/ServiceFeedback'],
        'overall_confidence' => 'medium', 'caveats' => 'Single signal',
    ])]]], 'usage' => ['completion_tokens' => 120]], 200)]);

    $gateway = app(LLMGateway::class);
    expect($gateway->available())->toBeTrue();
    $result = $gateway->complete(rootCauseRequest('rule: http_error_rate; token=abc123secret; failures: 180'));

    expect($result->ok)->toBeTrue()
        ->and($result->json['overall_confidence'])->toBe('medium')
        ->and($result->provider)->toBe('openai_compatible')->and($result->model)->toBe('test-model')->and($result->promptVersion)->toBe('v2')
        ->and($result->runId)->not->toBeNull();

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && str_contains($body['messages'][1]['content'], 'failures: 180')
            && ! str_contains($body['messages'][1]['content'], 'abc123secret') // redacted before sending
            && str_contains($body['messages'][0]['content'], 'never decide');
    });

    $run = IntelligenceAiRun::query()->firstOrFail();
    expect($run->status)->toBe('ok')->and($run->output['overall_confidence'])->toBe('medium')
        ->and(json_encode($run->input_summary))->not->toContain('failures: 180')
        ->and($run->subject_id)->toBe('INC-1')->and($run->output_tokens)->toBe(120);
});

test('a data class the settings do not allow is refused before anything is sent', function (): void {
    enableOpenAi();
    aiSetting('ai_outbound_data_classes', 'aggregates');
    Http::fake();

    $result = app(LLMGateway::class)->complete(rootCauseRequest());
    expect($result->ok)->toBeFalse()->and($result->reason)->toBe(AiResult::REFUSED)->and($result->error)->toContain('structured_signals');
    Http::assertNothingSent();
});

test('malformed JSON fails safely and repeated provider failures open the circuit breaker', function (): void {
    enableOpenAi();
    config(['intelligence.ai.breaker_failures' => 2]);
    Http::fake(['https://ai.example.test/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => 'not json at all']]]], 200)
        ->push([], 500)->push([], 500)->push([], 500)
        ->push([], 500)->push([], 500)->push([], 500)]);

    $gateway = app(LLMGateway::class);
    $invalid = $gateway->complete(rootCauseRequest());
    expect($invalid->ok)->toBeFalse()->and($invalid->reason)->toBe(AiResult::INVALID);

    $failed = $gateway->complete(rootCauseRequest());
    expect($failed->reason)->toBe(AiResult::FAILED);
    $gateway->complete(rootCauseRequest());
    expect($gateway->status()['breaker_open'])->toBeTrue()->and($gateway->available())->toBeFalse();

    $tripped = $gateway->complete(rootCauseRequest());
    expect($tripped->reason)->toBe(AiResult::UNAVAILABLE)->and($tripped->error)->toBe('circuit_open');
});

test('the daily cap bounds cost', function (): void {
    enableOpenAi();
    config(['intelligence.ai.daily_request_cap' => 1]);
    Http::fake(['https://ai.example.test/*' => Http::response(['choices' => [['message' => ['content' => '{"answer":"x","citations":[],"grounded":true,"confidence":"low"}']]]], 200)]);

    $gateway = app(LLMGateway::class);
    $first = $gateway->complete(new AiRequest(feature: 'knowledge_assistant', promptKey: 'knowledge_answer', input: '[1] Policy: ten days.', dataClasses: ['knowledge_documents'], variables: ['question' => 'How many days?']));
    expect($first->reason)->toBe(AiResult::REFUSED); // knowledge_documents is not an allowed outbound class in this test's settings

    aiSetting('ai_outbound_data_classes', 'knowledge_documents');
    $ok = $gateway->complete(new AiRequest(feature: 'knowledge_assistant', promptKey: 'knowledge_answer', input: '[1] Policy: ten days.', dataClasses: ['knowledge_documents'], variables: ['question' => 'How many days?']));
    expect($ok->ok)->toBeTrue();
    $capped = $gateway->complete(new AiRequest(feature: 'knowledge_assistant', promptKey: 'knowledge_answer', input: '[1] x', dataClasses: ['knowledge_documents']));
    expect($capped->ok)->toBeFalse()->and($capped->error)->toBe('daily_cap_reached');
});
