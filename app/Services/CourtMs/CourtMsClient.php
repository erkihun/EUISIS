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
     * Flow 5: a case the employee files from My Portal, with their grievance record
     * (`grievances`, a JSON string) and PDFs. Idempotent on `filing_id`.
     *
     * A case may name co-applicants (`co_applicants`, each confirms in their own My
     * Portal) and a lawyer from the court's register (`lawyer_license_number`, with
     * the power of attorney).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{name: string, contents: string, title: string, source?: string}>  $documents
     * @param  array{name: string, contents: string}|null  $powerOfAttorney
     * @return array{case_id: int, case_number: string, review_status: string, status: string}
     */
    public function fileCase(array $payload, array $documents = [], ?array $powerOfAttorney = null): array
    {
        $request = $this->request()->timeout(max(60, (int) config('court_ms.timeout', 20)));

        try {
            foreach ($documents as $i => $document) {
                $request = $request->attach("documents[{$i}]", $document['contents'], $document['name'], ['Content-Type' => 'application/pdf']);
            }
            if ($powerOfAttorney !== null) {
                $request = $request->attach('power_of_attorney', $powerOfAttorney['contents'], $powerOfAttorney['name'], ['Content-Type' => 'application/pdf']);
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
     * Flow 7 (ADR 0013 #14): cases filed against EUISIS institutions after a cursor (the court-ms case id).
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool}
     */
    public function institutionNewCases(int $since, int $limit = 100): array
    {
        $json = $this->postJson('/api/v1/integration/institutions/cases/new', ['since' => $since, 'limit' => $limit]);

        return [
            'data' => array_values((array) ($json['data'] ?? [])),
            'next_cursor' => (int) ($json['next_cursor'] ?? $since),
            'has_more' => (bool) ($json['has_more'] ?? false),
        ];
    }

    /**
     * Flow 7: one case as the respondent institution follows it.
     *
     * @return array<string, mixed>
     */
    public function institutionCase(int $caseId, string $organizationUuid): array
    {
        return (array) ($this->postJson("/api/v1/integration/institutions/cases/{$caseId}", ['organization_uuid' => $organizationUuid])['data'] ?? []);
    }

    /**
     * Flow 7: the institution asks for a closed case's record to take to the appellate court.
     *
     * @return array<string, mixed>
     */
    public function institutionAppeal(int $caseId, string $organizationUuid, string $appellantName, string $grounds): array
    {
        return (array) ($this->postJson("/api/v1/integration/institutions/cases/{$caseId}/appeal", [
            'organization_uuid' => $organizationUuid, 'appellant_name' => $appellantName, 'grounds' => $grounds,
        ])['data'] ?? []);
    }

    /** Flow 7: the appeal record PDF, once the registry released it to the institution. */
    public function institutionAppealRecord(int $caseId, string $organizationUuid): string
    {
        try {
            $response = $this->request()->accept('application/pdf')->timeout(max(120, (int) config('court_ms.timeout', 20)))
                ->post("/api/v1/integration/institutions/cases/{$caseId}/appeal-record", ['organization_uuid' => $organizationUuid]);
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }
        if (! $response->successful()) {
            $this->decode($response);
        }
        $body = $response->body();
        if (! str_starts_with($body, '%PDF')) {
            throw new CourtMsException("court-ms answered the appeal record of case {$caseId} with something other than a PDF.", retryable: false);
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function postJson(string $path, array $payload): array
    {
        try {
            return $this->decode($this->request()->post($path, $payload));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }
    }

    /**
     * Several parties (ADR 0013 #13): the employee's invitations to join a case and
     * their applications to intervene.
     *
     * @return array{invitations: list<array<string, mixed>>, interventions: list<array<string, mixed>>}
     */
    public function parties(string $employeeUuid): array
    {
        $data = $this->workspace('parties', $employeeUuid);

        return [
            'invitations' => array_values((array) ($data['invitations'] ?? [])),
            'interventions' => array_values((array) ($data['interventions'] ?? [])),
        ];
    }

    /**
     * The court's register of verified lawyers (name and licence number only).
     *
     * @return list<array{name: string, license_number: string}>
     */
    public function lawyers(?string $search = null): array
    {
        try {
            $json = $this->decode($this->request()->post('/api/v1/integration/people/lawyers', array_filter(['q' => $search])));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return array_values(array_map(fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'license_number' => (string) ($row['license_number'] ?? ''),
        ], (array) ($json['data'] ?? [])));
    }

    /**
     * The employee joins a case they were invited to, or declines. The employee's
     * record goes along, since joining may open their court account. Joining, they
     * may name a lawyer from the court's register, with the power of attorney.
     *
     * @param  array<string, mixed>  $person
     * @param  array{name: string, contents: string}|null  $powerOfAttorney
     * @return array<string, mixed>
     */
    public function respondToInvitation(int $partyId, array $person, bool $accept, ?string $lawyerLicenseNumber = null, ?array $powerOfAttorney = null): array
    {
        $url = "/api/v1/integration/people/parties/{$partyId}/respond";
        $payload = ['employee' => $person, 'accept' => $accept];

        try {
            if ($accept && $lawyerLicenseNumber !== null && $powerOfAttorney !== null) {
                $response = $this->request()->timeout(max(30, (int) config('court_ms.timeout', 20)))
                    ->attach('power_of_attorney', $powerOfAttorney['contents'], $powerOfAttorney['name'], ['Content-Type' => 'application/pdf'])
                    ->asMultipart()->post($url, $this->multipartFields([...$payload, 'accept' => '1', 'lawyer_license_number' => $lawyerLicenseNumber], []));
            } else {
                $response = $this->request()->post($url, $payload);
            }
            $json = $this->decode($response);
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return (array) ($json['data'] ?? []);
    }

    /**
     * The employee applies to intervene in another employee's case; the judge decides.
     *
     * @param  array<string, mixed>  $person
     * @param  array{name: string, contents: string}|null  $document
     * @return array<string, mixed>
     */
    public function intervene(array $person, string $caseNumber, string $statement, ?array $document = null): array
    {
        $request = $this->request()->timeout(max(30, (int) config('court_ms.timeout', 20)));
        $payload = ['employee' => $person, 'case_number' => $caseNumber, 'statement' => $statement];

        try {
            $response = $document === null
                ? $request->post('/api/v1/integration/people/interventions', $payload)
                : $request->attach('document', $document['contents'], $document['name'], ['Content-Type' => 'application/pdf'])
                    ->asMultipart()->post('/api/v1/integration/people/interventions', $this->multipartFields($payload, []));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return (array) ($this->decode($response)['data'] ?? []);
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
     * Flow 6: a call into the employee's case workspace in court-ms (cases/{id}/workspace,
     * cases/{id}/messages, …). The employee is named in the body, never in a URL.
     *
     * @param  array<string, mixed>  $data  the court-ms portal form's own field names
     * @param  array<string, array{name: string, contents: string, mime?: string}>  $files  by field name (`file`, `pdf`, `evidence_files[0]`)
     * @return array<string, mixed>
     */
    public function workspace(string $path, string $employeeUuid, array $data = [], array $files = []): array
    {
        $request = $this->request()->timeout(max(30, (int) config('court_ms.timeout', 20)));
        $url = '/api/v1/integration/people/'.ltrim($path, '/');
        $payload = ['employee_uuid' => $employeeUuid, ...$data];

        try {
            if ($files === []) {
                $response = $request->post($url, $payload);
            } else {
                foreach ($files as $field => $file) {
                    $request = $request->attach($field, $file['contents'], $file['name'], ['Content-Type' => $file['mime'] ?? 'application/octet-stream']);
                }
                $response = $request->asMultipart()->post($url, $this->multipartFields($payload, []));
            }
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return (array) ($this->decode($response)['data'] ?? []);
    }

    /**
     * Flow 6: one of the employee's case files (kind: evidence, file, response, reply,
     * decision, letter, receipt, hearing), as court-ms serves it to the parties.
     *
     * @return array{body: string, type: string, name: string}
     */
    public function workspaceFile(int $caseId, string $employeeUuid, string $kind, ?int $id = null, ?int $responseId = null): array
    {
        try {
            // The appeal record is built on request, its annexes merged in: it takes longer.
            $response = $this->request()->accept('*/*')->timeout(max($kind === 'appeal_record' ? 120 : 30, (int) config('court_ms.timeout', 20)))
                ->post("/api/v1/integration/people/cases/{$caseId}/download", array_filter([
                    'employee_uuid' => $employeeUuid, 'kind' => $kind, 'id' => $id, 'response_id' => $responseId,
                ], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }
        if (! $response->successful()) {
            $this->decode($response);
        }

        $name = 'court-'.$kind.'.'.($kind === 'hearing' ? 'ics' : 'pdf');
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', (string) $response->header('Content-Disposition'), $match) === 1) {
            $name = basename(rawurldecode($match[1]));
        }

        return ['body' => $response->body(), 'type' => $response->header('Content-Type') ?: 'application/octet-stream', 'name' => $name];
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
