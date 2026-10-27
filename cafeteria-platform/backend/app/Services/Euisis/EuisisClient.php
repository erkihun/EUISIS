<?php

declare(strict_types=1);

namespace App\Services\Euisis;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The EUISIS integration API (`/api/v1`, Bearer token of the application
 * registered in EUISIS › System Settings › API Management for this platform).
 * Every call is a plain read; EUISIS stays the writer of master data.
 */
class EuisisClient
{
    public function configured(): bool
    {
        return config('euisis.url') !== '' && config('euisis.token') !== '';
    }

    public function baseUrl(): string
    {
        return (string) config('euisis.url');
    }

    /** @return array<string, mixed> the EUISIS service descriptor (a reachability check) */
    public function ping(): array
    {
        return $this->request()->get('/api/v1/organizations/directory', ['per_page' => 1])->throw()->json() ?? [];
    }

    /**
     * One directory page of organizations.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function organizations(int $page, ?string $updatedAfter = null): array
    {
        return $this->page('/api/v1/organizations/directory', $page, $updatedAfter);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function organizationUnits(string $organizationId, int $page): array
    {
        return $this->page("/api/v1/organizations/{$organizationId}/units", $page);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function employees(int $page, ?string $updatedAfter = null): array
    {
        return $this->page('/api/v1/employees', $page, $updatedAfter);
    }

    /** @return array<string, mixed>|null */
    public function employee(string $employeeUuid): ?array
    {
        $response = $this->request()->get("/api/v1/employees/{$employeeUuid}");
        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json('data');
    }

    /**
     * The card a printed QR code refers to, with its holder's placement.
     * Null when EUISIS does not know the card (or it is outside this platform's bound).
     *
     * @return array<string, mixed>|null
     */
    public function verifyCardReference(string $publicCardUuid): ?array
    {
        $response = $this->request()->get("/api/v1/id-cards/verify/{$publicCardUuid}");
        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json();
    }

    /**
     * A legacy "cardId|token" scan value: EUISIS checks the secret and answers
     * like verifyCardReference() when the card is live.
     *
     * @return array<string, mixed>|null
     */
    public function verifyScanValue(string $scanValue): ?array
    {
        $response = $this->request()->post('/api/v1/cards/verify', ['token' => $scanValue, 'service_type' => 'cafeteria']);
        if (in_array($response->status(), [404, 422], true)) {
            return null;
        }
        $result = $response->throw()->json();
        if (! ($result['allowed'] ?? false)) {
            return null;
        }
        // The service verification carries no card detail; the public reference does.
        [$cardId] = explode('|', $scanValue, 2);
        $card = $this->request()->get("/api/v1/cards/{$cardId}");
        if ($card->status() === 404) {
            return null;
        }
        $data = $card->throw()->json('data') ?? [];
        $reference = (string) ($data['card_uuid'] ?? $data['public_card_uuid'] ?? '');

        return $reference === '' ? null : $this->verifyCardReference($reference);
    }

    /** @return array<string, mixed> */
    public function eligibility(string $employeeUuid, string $serviceType = 'cafeteria'): array
    {
        return $this->request()->get("/api/v1/employees/{$employeeUuid}/service-eligibility", ['service_type' => $serviceType])->throw()->json() ?? [];
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    private function page(string $path, int $page, ?string $updatedAfter = null): array
    {
        $query = ['page' => $page, 'per_page' => max(1, (int) config('euisis.page_size'))];
        if ($updatedAfter !== null) {
            $query['updated_after'] = $updatedAfter;
        }
        try {
            $body = $this->request()->get($path, $query)->throw()->json();
        } catch (RequestException $e) {
            throw new RuntimeException("EUISIS answered {$e->response->status()} for {$path}: ".mb_substr((string) $e->response->body(), 0, 300), previous: $e);
        }

        return ['data' => $body['data'] ?? [], 'meta' => $body['meta'] ?? ['current_page' => $page, 'last_page' => $page, 'total' => count($body['data'] ?? [])]];
    }

    protected function request(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('EUISIS is not configured: set EUISIS_URL and EUISIS_TOKEN.');
        }

        return Http::baseUrl($this->baseUrl())
            ->withToken((string) config('euisis.token'))
            ->acceptJson()
            ->timeout(max(1, (int) config('euisis.timeout_seconds')))
            ->connectTimeout(5);
    }
}
