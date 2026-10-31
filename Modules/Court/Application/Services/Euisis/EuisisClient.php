<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The EUISIS partner API v1 as court-ms uses it (EUISIS docs/integration/*):
 * a registered-application bearer token, read-only scopes, JSON envelopes of
 * `{data, meta}` for lists and `{data}` for one record, UUID ids.
 */
final class EuisisClient
{
    public function enabled(): bool
    {
        return (bool) config('court.euisis.enabled') && $this->configured();
    }

    public function configured(): bool
    {
        return filled(config('court.euisis.url')) && filled(config('court.euisis.token'));
    }

    /** @return array{cursor: string, as_of: ?string} */
    public function watermark(): array
    {
        $json = $this->get('/api/v1/changes/watermark');

        return ['cursor' => (string) ($json['cursor'] ?? ''), 'as_of' => $json['as_of'] ?? null];
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string, has_more: bool} */
    public function changes(?string $cursor, int $limit = 200): array
    {
        $json = $this->get('/api/v1/changes', array_filter([
            'cursor' => $cursor,
            'limit' => max(1, min(500, $limit)),
            'aggregate_types' => 'organization,organization_unit,position,employee,assignment,employee_placement',
        ], fn ($v) => $v !== null && $v !== ''));

        return [
            'data' => array_values((array) ($json['data'] ?? [])),
            'next_cursor' => $json['next_cursor'] ?? null,
            'has_more' => (bool) ($json['has_more'] ?? false),
        ];
    }

    /** @return array{data: list<array<string, mixed>>, last_page: int} */
    public function organizations(int $page): array
    {
        return $this->page('/api/v1/organizations/directory', $page);
    }

    /** @return array<string, mixed>|null */
    public function organization(string $uuid): ?array
    {
        return $this->getOne("/api/v1/organizations/{$uuid}");
    }

    /** @return array{data: list<array<string, mixed>>, last_page: int} */
    public function units(string $organizationUuid, int $page): array
    {
        return $this->page("/api/v1/organizations/{$organizationUuid}/units", $page);
    }

    /** @return array{data: list<array<string, mixed>>, last_page: int} */
    public function positions(string $organizationUuid, int $page): array
    {
        return $this->page("/api/v1/organizations/{$organizationUuid}/positions", $page);
    }

    /** Employees matching an exact employee number (normally one). @return list<array<string, mixed>> */
    public function employeesByNumber(string $employeeNumber): array
    {
        $json = $this->get('/api/v1/employees', ['employee_number' => $employeeNumber, 'per_page' => 10]);

        return array_values((array) ($json['data'] ?? []));
    }

    /**
     * Employees whose current assignment is in one unit, by the organization's and the
     * unit's codes (unit codes are unique only inside an organization).
     *
     * @return array{data: list<array<string, mixed>>, last_page: int}
     */
    public function employeesInUnit(string $organizationCode, string $unitCode, int $page): array
    {
        $json = $this->get('/api/v1/employees', [
            'organization_code' => $organizationCode,
            'organization_unit_code' => $unitCode,
            'page' => max(1, $page),
            'per_page' => 100,
        ]);

        return [
            'data' => array_values((array) ($json['data'] ?? [])),
            'last_page' => max(1, (int) ($json['meta']['last_page'] ?? 1)),
        ];
    }

    /** @return array<string, mixed>|null */
    public function employee(string $uuid): ?array
    {
        return $this->getOne("/api/v1/employees/{$uuid}");
    }

    /** @return array{data: list<array<string, mixed>>, last_page: int} */
    private function page(string $path, int $page): array
    {
        $json = $this->get($path, ['page' => max(1, $page), 'per_page' => max(1, min(100, (int) config('court.euisis.per_page', 100)))]);

        return [
            'data' => array_values((array) ($json['data'] ?? [])),
            'last_page' => max(1, (int) ($json['meta']['last_page'] ?? 1)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function getOne(string $path): ?array
    {
        try {
            $json = $this->get($path);
        } catch (EuisisException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }

        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        if (! $this->configured()) {
            throw new EuisisException('EUISIS_URL and EUISIS_TOKEN are not configured.');
        }

        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            throw new EuisisException('EUISIS could not be reached: '.$e->getMessage());
        }

        return $this->decode($response, $path);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $path): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $json = (array) $response->json();
        throw new EuisisException(
            sprintf('EUISIS %s answered %d%s.', $path, $response->status(), isset($json['error_code']) ? ' ('.$json['error_code'].')' : ''),
            $response->status(),
            isset($json['error_code']) ? (string) $json['error_code'] : null,
            isset($json['request_id']) ? (string) $json['request_id'] : $response->header('X-Request-Id'),
        );
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('court.euisis.url'))
            ->withToken((string) config('court.euisis.token'))
            ->acceptJson()
            ->timeout(max(1, (int) config('court.euisis.timeout', 15)))
            ->withOptions(['verify' => (bool) config('court.euisis.verify_tls', true)])
            ->retry(2, 300, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }
}
