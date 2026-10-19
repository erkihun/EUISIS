<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The Anthropic Messages API. Key, model and version from INTELLIGENCE_ANTHROPIC_*; the settings may override the model alias. */
final class AnthropicProvider implements Provider
{
    /** @param  array{base_url?: ?string, api_key?: ?string, model?: ?string, version?: ?string}  $config */
    public function __construct(private readonly array $config, private readonly ?string $modelOverride = null) {}

    public function configured(): bool
    {
        return ! empty($this->config['api_key']) && $this->model() !== null;
    }

    public function model(): ?string
    {
        $model = $this->modelOverride ?: ($this->config['model'] ?? null);

        return $model !== null && $model !== '' ? (string) $model : null;
    }

    public function chat(string $system, string $user, int $maxOutputTokens, int $timeoutSeconds): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) $this->config['api_key'],
            'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
        ])
            ->acceptJson()
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(10, $timeoutSeconds))
            ->retry(2, 500, throw: false)
            ->post(rtrim((string) ($this->config['base_url'] ?? 'https://api.anthropic.com'), '/').'/v1/messages', [
                'model' => $this->model(),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
                'max_tokens' => $maxOutputTokens,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status());
        }
        $blocks = (array) data_get($response->json(), 'content', []);
        $text = implode('', array_map(fn ($b): string => (string) ($b['text'] ?? ''), array_filter($blocks, fn ($b): bool => ($b['type'] ?? '') === 'text')));
        if ($text === '') {
            throw new RuntimeException('provider_empty_response');
        }

        return ['text' => $text, 'output_tokens' => data_get($response->json(), 'usage.output_tokens')];
    }
}
