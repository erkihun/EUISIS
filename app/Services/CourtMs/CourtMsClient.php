<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The court-ms integration API (court-ms docs/euisis-integration.md):
 * a bearer token from `php artisan euisis:integration-token`, case types,
 * referrals (idempotent on the grievance UUID) and the update list since a
 * cursor. ADR 0013.
 */
final class CourtMsClient
{
    public function enabled(): bool
    {
        return (bool) config('court_ms.enabled') && filled(config('court_ms.url')) && filled(config('court_ms.token'));
    }

    /** court-ms case types, cached for ten minutes; empty when court-ms cannot be reached. @return list<array{id: int, name: string}> */
    public function caseTypes(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return Cache::remember('court-ms:case-types', 600, function (): array {
            try {
                return array_values(array_map(
                    fn (array $t): array => ['id' => (int) $t['id'], 'name' => (string) $t['name']],
                    (array) ($this->decode($this->request()->get('/api/v1/integration/case-types'))['data'] ?? []),
                ));
            } catch (CourtMsException|ConnectionException) {
                return [];
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{name: string, contents: string, title: string}>  $documents
     * @return array<string, mixed> court-ms's referral record
     */
    public function refer(array $payload, array $documents = []): array
    {
        $request = $this->request()->timeout(max(30, (int) config('court_ms.timeout', 20)));

        try {
            if ($documents === []) {
                $response = $request->post('/api/v1/integration/referrals', $payload);
            } else {
                foreach ($documents as $i => $document) {
                    $request = $request->attach("documents[{$i}]", $document['contents'], $document['name'], ['Content-Type' => 'application/pdf']);
                }
                $response = $request->asMultipart()->post('/api/v1/integration/referrals', $this->multipartFields($payload, $documents));
            }
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return (array) ($this->decode($response)['data'] ?? []);
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool} */
    public function updates(int $since, int $limit = 100): array
    {
        try {
            $json = $this->decode($this->request()->get('/api/v1/integration/referrals/updates', ['since' => $since, 'limit' => $limit]));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return [
            'data' => array_values((array) ($json['data'] ?? [])),
            'next_cursor' => (int) ($json['next_cursor'] ?? $since),
            'has_more' => (bool) ($json['has_more'] ?? false),
        ];
    }

    /**
     * Multipart sends flat fields: nested arrays become `complainant[name]`.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{title: string}>  $documents
     * @return list<array{name: string, contents: string}>
     */
    private function multipartFields(array $payload, array $documents): array
    {
        $fields = [];
        $flatten = function (array $values, string $prefix) use (&$fields, &$flatten): void {
            foreach ($values as $key => $value) {
                $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
                if (is_array($value)) {
                    $flatten($value, $name);
                } elseif ($value !== null) {
                    $fields[] = ['name' => $name, 'contents' => is_bool($value) ? ($value ? '1' : '0') : (string) $value];
                }
            }
        };
        $flatten($payload, '');
        foreach ($documents as $i => $document) {
            $fields[] = ['name' => "document_titles[{$i}]", 'contents' => $document['title']];
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $json = (array) $response->json();
        $status = $response->status();
        $message = (string) ($json['message'] ?? 'court-ms answered '.$status);

        throw new CourtMsException(
            "court-ms answered {$status}: {$message}",
            $status,
            $status >= 500 || in_array($status, [408, 429], true),
            (array) ($json['errors'] ?? []),
            isset($json['request_id']) ? (string) $json['request_id'] : $response->header('X-Request-ID'),
        );
    }

    private function request(): PendingRequest
    {
        if (! $this->enabled()) {
            throw new CourtMsException('The court-ms connection is not configured (COURT_MS_ENABLED, COURT_MS_URL, COURT_MS_TOKEN).', retryable: false);
        }

        return Http::baseUrl((string) config('court_ms.url'))
            ->withToken((string) config('court_ms.token'))
            ->acceptJson()
            ->timeout(max(1, (int) config('court_ms.timeout', 20)))
            ->withOptions(['verify' => (bool) config('court_ms.verify_tls', true)]);
    }
}
