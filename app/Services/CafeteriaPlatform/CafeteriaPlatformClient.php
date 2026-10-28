<?php

declare(strict_types=1);

namespace App\Services\CafeteriaPlatform;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What EUISIS reads from the cafeteria platform (ADR 0014): an employee's
 * cafeteria standing and meals, the cafeterias, and a period's totals. All
 * reads; the cafeteria's rules and money stay on the platform.
 */
class CafeteriaPlatformClient
{
    public function enabled(): bool
    {
        return config('cafeteria_platform.mode') === 'platform' && $this->configured();
    }

    /** Whether the employee portal shows the platform's view of an employee's cafeteria standing. */
    public function servesEmployeePortal(): bool
    {
        $setting = config('cafeteria_platform.employee_portal');

        // Unset or blank (`CAFETERIA_PLATFORM_EMPLOYEE_PORTAL=` as .env.example ships it) follows the mode.
        return $this->configured() && ($setting === null || $setting === '' ? $this->enabled() : filter_var($setting, FILTER_VALIDATE_BOOL));
    }

    public function configured(): bool
    {
        return (string) config('cafeteria_platform.url') !== '' && (string) config('cafeteria_platform.token') !== '';
    }

    public function url(string $path = ''): string
    {
        return (string) config('cafeteria_platform.url').($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /** @return array<string, mixed>|null null when the platform does not know the employee */
    public function entitlement(string $employeeUuid): ?array
    {
        $response = $this->request()->get("/api/v1/integration/cafeteria/employees/{$employeeUuid}/entitlement");
        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json('data');
    }

    /** @return list<array<string, mixed>> */
    public function transactions(string $employeeUuid, int $limit = 100): array
    {
        $response = $this->request()->get("/api/v1/integration/cafeteria/employees/{$employeeUuid}/transactions", ['limit' => $limit]);
        if ($response->status() === 404) {
            return [];
        }

        return $response->throw()->json('data') ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function providers(): array
    {
        return $this->request()->get('/api/v1/integration/cafeteria/providers')->throw()->json('data') ?? [];
    }

    /** @return array<string, mixed> */
    public function summary(?string $from = null, ?string $to = null): array
    {
        return $this->request()->get('/api/v1/integration/cafeteria/summary', array_filter(['from' => $from, 'to' => $to]))->throw()->json('data') ?? [];
    }

    private function request(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('The cafeteria platform is not configured: set CAFETERIA_PLATFORM_URL and CAFETERIA_PLATFORM_TOKEN.');
        }

        return Http::baseUrl((string) config('cafeteria_platform.url'))
            ->withToken((string) config('cafeteria_platform.token'))
            ->acceptJson()
            ->withOptions(['verify' => (bool) config('cafeteria_platform.verify_tls')])
            ->timeout(max(1, (int) config('cafeteria_platform.timeout')))
            ->connectTimeout(5);
    }
}
