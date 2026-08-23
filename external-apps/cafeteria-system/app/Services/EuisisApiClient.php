<?php

declare(strict_types=1);

namespace CafeteriaSystem\Services;

use CafeteriaSystem\Models\CafeteriaApiLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The ONLY channel between the Cafeteria System and EUISIS.
 *
 * This client speaks HTTP to the EUISIS integration API using a Sanctum bearer
 * token issued through EUISIS → System Settings → API Management. There is no
 * database connection to EUISIS anywhere in this application, by design: if
 * EUISIS is unreachable the cafeteria fails closed rather than serving on
 * stale or assumed data.
 *
 * Every call is logged to cafeteria_api_logs with metadata only — never a
 * response body, so employee data cannot accumulate in the log table.
 */
readonly class EuisisApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('euisis.base_url'), '/'),
            (string) config('euisis.token'),
            (int) config('euisis.timeout', 10),
        );
    }

    /**
     * Verify a scanned card token (the stable public card UUID in the QR).
     *
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function verifyCard(string $cardToken): array
    {
        return $this->send('GET', "/api/v1/id-cards/verify/{$cardToken}");
    }

    /**
     * Ask EUISIS whether an employee may receive a service right now.
     *
     * EUISIS remains the sole authority for this decision; the cafeteria never
     * infers eligibility from a cached result.
     *
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function checkServiceEligibility(string $employeeId, string $serviceType = 'cafeteria'): array
    {
        return $this->send(
            'GET',
            "/api/v1/employees/{$employeeId}/service-eligibility",
            ['service_type' => $serviceType],
        );
    }

    /**
     * Fetch the EUISIS organization directory so an administrator can pick a
     * real organization when creating an assignment, instead of typing a code.
     *
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function organizations(string $search = '', int $limit = 200): array
    {
        return $this->send('GET', '/api/v1/organizations', [
            'search' => $search,
            'limit' => $limit,
        ]);
    }

    /**
     * Record the service against EUISIS.
     *
     * NOTE: the endpoint named in the integration brief
     * (`POST /api/v1/service-transactions/verify-and-record`) does not exist in
     * EUISIS today. This method targets the endpoint that does
     * (`POST /api/v1/services/{serviceType}/transactions`) and is isolated here
     * so a future contract change touches one method. See README "Blockers".
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function recordServiceTransaction(array $payload, string $serviceType = 'cafeteria', ?string $idempotencyKey = null): array
    {
        return $this->send(
            'POST',
            "/api/v1/services/{$serviceType}/transactions",
            $payload,
            $idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    private function send(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $startedAt = microtime(true);

        if ($this->token === '') {
            return $this->fail($method, $path, 0, 'missing_api_token', $startedAt);
        }

        $request = Http::withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout);

        // Replay protection for writes: EUISIS returns the original response
        // for a repeated key rather than recording a second transaction.
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        try {
            $response = $method === 'GET'
                ? $request->get($this->baseUrl.$path, $payload)
                : $request->send($method, $this->baseUrl.$path, ['json' => $payload]);
        } catch (ConnectionException $exception) {
            // Fail closed — an unreachable EUISIS must never imply "eligible".
            return $this->fail($method, $path, 0, 'connection_failed', $startedAt);
        }

        return $this->result($method, $path, $response, $startedAt);
    }

    /**
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    private function result(string $method, string $path, Response $response, float $startedAt): array
    {
        $status = $response->status();
        $ok = $response->successful();

        $error = match (true) {
            $ok => null,
            $status === 401 => 'unauthorized',
            $status === 403 => (string) ($response->json('error_code') ?? 'forbidden'),
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            default => 'request_failed',
        };

        $this->log($method, $path, $status, $ok, $error, $startedAt);

        return [
            'ok' => $ok,
            'status' => $status,
            'data' => is_array($response->json()) ? $response->json() : [],
            'error' => $error,
        ];
    }

    /**
     * @return array{ok: false, status: int, data: array<string, mixed>, error: string}
     */
    private function fail(string $method, string $path, int $status, string $error, float $startedAt): array
    {
        $this->log($method, $path, $status, false, $error, $startedAt);

        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => $error];
    }

    private function log(string $method, string $path, int $status, bool $success, ?string $error, float $startedAt): void
    {
        CafeteriaApiLog::query()->create([
            'endpoint' => $path,
            'method' => $method,
            'status_code' => $status,
            'success' => $success,
            'error_code' => $error,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_at' => now(),
        ]);
    }
}
