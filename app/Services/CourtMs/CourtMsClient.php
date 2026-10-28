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

    /** Whether My Portal shows the court section (Flow 4). */
    public function employeePortalEnabled(): bool
    {
        return $this->enabled() && (bool) config('court_ms.employee_portal');
    }

    /**
     * Flow 4: an employee's court summary (`linked`, counts, cases, notices), its notices worded in `$locale`.
     *
     * @param  array{uuid: string, national_id?: ?string, email?: ?string}  $identity
     * @return array<string, mixed>
     */
    public function employeeSummary(array $identity, ?string $locale = null): array
    {
        try {
            $json = $this->decode($this->request()->post('/api/v1/integration/people/court-summary', array_filter(['employee' => $identity, 'locale' => $locale])));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return (array) ($json['data'] ?? []);
    }

    /**
     * Flow 4: a one-time URL that opens the court portal signed in as the employee.
     * Only a URL on court-ms's own public host is accepted.
     *
     * @param  array<string, mixed>  $employee
     */
    public function signInUrl(array $employee, string $target): string
    {
        try {
            $json = $this->decode($this->request()->post('/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => $target]));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        $url = (string) ($json['data']['url'] ?? '');
        $expected = strtolower((string) parse_url((string) (config('court_ms.public_url') ?: config('court_ms.url')), PHP_URL_HOST));
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || strtolower($parts['host'] ?? '') !== $expected) {
            throw new CourtMsException('court-ms answered with a sign-in link to another site.', retryable: false);
        }

        return $url;
    }

    /**
     * Flow 5: a case the employee files from My Portal, with their grievance record
     * (`grievances`, a JSON string) and PDFs. Idempotent on `filing_id`.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{name: string, contents: string, title: string, source?: string}>  $documents
     * @return array{case_id: int, case_number: string, review_status: string, status: string}
     */
    public function fileCase(array $payload, array $documents = []): array
    {
        $request = $this->request()->timeout(max(60, (int) config('court_ms.timeout', 20)));

        try {
            foreach ($documents as $i => $document) {
                $request = $request->attach("documents[{$i}]", $document['contents'], $document['name'], ['Content-Type' => 'application/pdf']);
            }
            $response = $request->asMultipart()->post('/api/v1/integration/people/cases', $this->multipartFields($payload, $documents));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        $data = (array) ($this->decode($response)['data'] ?? []);

        return [
            'case_id' => (int) ($data['case_id'] ?? 0),
            'case_number' => (string) ($data['case_number'] ?? ''),
            'review_status' => (string) ($data['review_status'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
        ];
    }

    /**
     * Flow 5: approved court letters for EUISIS institutions, after a cursor.
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool}
     */
    public function letterUpdates(int $since, int $limit = 100): array
    {
        try {
            $json = $this->decode($this->request()->get('/api/v1/integration/letters/updates', ['since' => $since, 'limit' => $limit]));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return [
            'data' => array_values((array) ($json['data'] ?? [])),
            'next_cursor' => (int) ($json['next_cursor'] ?? $since),
            'has_more' => (bool) ($json['has_more'] ?? false),
        ];
    }

    /** Flow 5: an approved court letter's PDF. */
    public function letterPdf(int $letterId): string
    {
        try {
            $response = $this->request()->accept('application/pdf')->get("/api/v1/integration/letters/{$letterId}/pdf");
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }
        if (! $response->successful()) {
            $this->decode($response);
        }

        $body = $response->body();
        if (! str_starts_with($body, '%PDF')) {
            throw new CourtMsException("court-ms answered letter {$letterId} with something other than a PDF.", retryable: false);
        }

        return $body;
    }

    /**
     * Multipart sends flat fields: nested arrays become `complainant[name]`.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{title: string, source?: string}>  $documents
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
            if (isset($document['source'])) {
                $fields[] = ['name' => "document_sources[{$i}]", 'contents' => $document['source']];
            }
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
            isset($json['error_code']) ? (string) $json['error_code'] : null,
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
