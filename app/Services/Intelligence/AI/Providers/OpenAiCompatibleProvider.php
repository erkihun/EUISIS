<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Any OpenAI-style `/v1/chat/completions` endpoint (OpenAI, Azure-compatible
 * gateways, local servers). Base URL, key and default model come from
 * INTELLIGENCE_AI_BASE_URL / _API_KEY / _MODEL; the settings may override the
 * model alias. One attempt plus one retry on a transport error, bounded.
 */
final class OpenAiCompatibleProvider implements Provider
{
    use VerifiesTls;

    /** @param  array{base_url?: ?string, api_key?: ?string, model?: ?string}  $config */
    public function __construct(private readonly array $config, private readonly ?string $modelOverride = null) {}

    public function configured(): bool
    {
        return ! empty($this->config['base_url']) && ! empty($this->config['api_key']) && $this->model() !== null;
    }

    public function model(): ?string
    {
        $model = $this->modelOverride ?: ($this->config['model'] ?? null);

        return $model !== null && $model !== '' ? (string) $model : null;
    }

    public function chat(string $system, string $user, int $maxOutputTokens, int $timeoutSeconds): array
    {
        $response = Http::withToken((string) $this->config['api_key'])
            ->withOptions(['verify' => self::verify()])
            ->acceptJson()
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(10, $timeoutSeconds))
            ->retry(2, 500, throw: false)
            ->post(rtrim((string) $this->config['base_url'], '/').'/v1/chat/completions', [
                'model' => $this->model(),
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                'max_tokens' => $maxOutputTokens,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status());
        }
        $text = (string) data_get($response->json(), 'choices.0.message.content', '');
        if ($text === '') {
            throw new RuntimeException('provider_empty_response');
        }

        return ['text' => $text, 'output_tokens' => data_get($response->json(), 'usage.completion_tokens')];
    }
}
