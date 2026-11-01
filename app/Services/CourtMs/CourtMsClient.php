<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Court\Contracts\CourtIntegration;
use Modules\Court\Contracts\Data\CourtAnswer;

/**
 * What EUISIS asks of the court (court-ms docs/euisis-integration.md): case
 * types, referrals (idempotent on the grievance UUID) and the update list since
 * a cursor, filings, the employee's case workspace, parties, institutions'
 * cases and letters. ADR 0013.
 *
 * With the court module in EUISIS (ADR 0020) and no COURT_MS_URL, every call
 * goes to its CourtIntegration contract in this process, like any module call.
 * A COURT_MS_URL with a token still reaches the separate court-ms over HTTP,
 * for the time both run.
 */
final class CourtMsClient
{
    private const API = '/api/v1/integration';

    public function enabled(): bool
    {
        return (bool) config('court_ms.enabled') && ($this->inModule() || (filled(config('court_ms.url')) && filled(config('court_ms.token'))));
    }

    /** The court is the module in this application (no COURT_MS_URL). */
    public function inModule(): bool
    {
        return blank(config('court_ms.url')) && app()->bound(CourtIntegration::class);
    }

    /** The court's case types, cached for ten minutes; empty when the court cannot answer. @return list<array{id: int, name: string}> */
    public function caseTypes(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return Cache::remember('court-ms:case-types', 600, function (): array {
            try {
                return array_values(array_map(
                    fn (array $t): array => ['id' => (int) $t['id'], 'name' => (string) $t['name']],
                    (array) ($this->decode($this->send('GET', '/case-types'))['data'] ?? []),
                ));
            } catch (CourtMsException) {
                return [];
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{name: string, contents: string, title: string}>  $documents
     * @return array<string, mixed> the court's referral record
     */
    public function refer(array $payload, array $documents = []): array
    {
        [$payload, $files] = $this->withDocuments($payload, $documents);

        return (array) ($this->decode($this->send('POST', '/referrals', $payload, $files, 30))['data'] ?? []);
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool} */
    public function updates(int $since, int $limit = 100): array
    {
        return $this->page($this->decode($this->send('GET', '/referrals/updates', ['since' => $since, 'limit' => $limit])), $since);
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
        return (array) ($this->decode($this->send('POST', '/people/court-summary', array_filter(['employee' => $identity, 'locale' => $locale])))['data'] ?? []);
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
        [$payload, $files] = $this->withDocuments($payload, $documents);
        if ($powerOfAttorney !== null) {
            $files['power_of_attorney'] = [...$powerOfAttorney, 'mime' => 'application/pdf'];
        }
        // A filing always goes as a form: court-ms reads its fields as flat form fields.
        $data = (array) ($this->decode($this->send('POST', '/people/cases', $payload, $files, 60, multipart: true))['data'] ?? []);

        return [
            'case_id' => (int) ($data['case_id'] ?? 0),
            'case_number' => (string) ($data['case_number'] ?? ''),
            'review_status' => (string) ($data['review_status'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
        ];
    }

    /**
     * Flow 7 (ADR 0013 #14): cases filed against EUISIS institutions after a cursor (the court's case id).
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool}
     */
    public function institutionNewCases(int $since, int $limit = 100): array
    {
        return $this->page($this->decode($this->send('POST', '/institutions/cases/new', ['since' => $since, 'limit' => $limit])), $since);
    }

    /**
     * Flow 7: one case as the respondent institution follows it.
     *
     * @return array<string, mixed>
     */
    public function institutionCase(int $caseId, string $organizationUuid): array
    {
        return (array) ($this->decode($this->send('POST', "/institutions/cases/{$caseId}", ['organization_uuid' => $organizationUuid]))['data'] ?? []);
    }

    /**
     * Flow 7: the institution asks for a closed case's record to take to the appellate court.
     *
     * @return array<string, mixed>
     */
    public function institutionAppeal(int $caseId, string $organizationUuid, string $appellantName, string $grounds): array
    {
        return (array) ($this->decode($this->send('POST', "/institutions/cases/{$caseId}/appeal", [
            'organization_uuid' => $organizationUuid, 'appellant_name' => $appellantName, 'grounds' => $grounds,
        ]))['data'] ?? []);
    }

    /** Flow 7: the appeal record PDF, once the registry released it to the institution. */
    public function institutionAppealRecord(int $caseId, string $organizationUuid): string
    {
        $answer = $this->send('POST', "/institutions/cases/{$caseId}/appeal-record", ['organization_uuid' => $organizationUuid], [], 120, 'application/pdf');
        if (! $answer->successful()) {
            $this->decode($answer);
        }
        if (! str_starts_with($answer->body, '%PDF')) {
            throw new CourtMsException("The court answered the appeal record of case {$caseId} with something other than a PDF.", retryable: false);
        }

        return $answer->body;
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
        $json = $this->decode($this->send('POST', '/people/lawyers', array_filter(['q' => $search])));

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
        $path = "/people/parties/{$partyId}/respond";
        $payload = ['employee' => $person, 'accept' => $accept];
        $answer = $accept && $lawyerLicenseNumber !== null && $powerOfAttorney !== null
            ? $this->send('POST', $path, [...$payload, 'accept' => '1', 'lawyer_license_number' => $lawyerLicenseNumber], ['power_of_attorney' => [...$powerOfAttorney, 'mime' => 'application/pdf']], 30)
            : $this->send('POST', $path, $payload);

        return (array) ($this->decode($answer)['data'] ?? []);
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
        $payload = ['employee' => $person, 'case_number' => $caseNumber, 'statement' => $statement];
        $files = $document === null ? [] : ['document' => [...$document, 'mime' => 'application/pdf']];

        return (array) ($this->decode($this->send('POST', '/people/interventions', $payload, $files, 30))['data'] ?? []);
    }

    /**
     * Flow 5: approved court letters for EUISIS institutions, after a cursor.
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool}
     */
    public function letterUpdates(int $since, int $limit = 100): array
    {
        return $this->page($this->decode($this->send('GET', '/letters/updates', ['since' => $since, 'limit' => $limit])), $since);
    }

    /** Flow 5: an approved court letter's PDF. */
    public function letterPdf(int $letterId): string
    {
        $answer = $this->send('GET', "/letters/{$letterId}/pdf", [], [], null, 'application/pdf');
        if (! $answer->successful()) {
            $this->decode($answer);
        }
        if (! str_starts_with($answer->body, '%PDF')) {
            throw new CourtMsException("The court answered letter {$letterId} with something other than a PDF.", retryable: false);
        }

        return $answer->body;
    }

    /**
     * Flow 6: a call into the employee's case workspace in the court (cases/{id}/workspace,
     * cases/{id}/messages, …). The employee is named in the body, never in a URL.
     *
     * @param  array<string, mixed>  $data  the court portal form's own field names
     * @param  array<string, array{name: string, contents: string, mime?: string}>  $files  by field name (`file`, `pdf`, `evidence_files[0]`)
     * @return array<string, mixed>
     */
    public function workspace(string $path, string $employeeUuid, array $data = [], array $files = []): array
    {
        $answer = $this->send('POST', '/people/'.ltrim($path, '/'), ['employee_uuid' => $employeeUuid, ...$data], $this->fileFields($files), 30);

        return (array) ($this->decode($answer)['data'] ?? []);
    }

    /**
     * Flow 6: one of the employee's case files (kind: evidence, file, response, reply,
     * decision, letter, receipt, hearing), as the court serves it to the parties.
     *
     * @return array{body: string, type: string, name: string}
     */
    public function workspaceFile(int $caseId, string $employeeUuid, string $kind, ?int $id = null, ?int $responseId = null): array
    {
        // The appeal record is built on request, its annexes merged in: it takes longer.
        $answer = $this->send('POST', "/people/cases/{$caseId}/download", array_filter([
            'employee_uuid' => $employeeUuid, 'kind' => $kind, 'id' => $id, 'response_id' => $responseId,
        ], fn ($value) => $value !== null), [], $kind === 'appeal_record' ? 120 : 30, '*/*');
        if (! $answer->successful()) {
            $this->decode($answer);
        }

        $name = 'court-'.$kind.'.'.($kind === 'hearing' ? 'ics' : 'pdf');
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', (string) $answer->header('Content-Disposition'), $match) === 1) {
            $name = basename(rawurldecode($match[1]));
        }

        return ['body' => $answer->body, 'type' => $answer->header('Content-Type') ?: 'application/octet-stream', 'name' => $name];
    }

    /**
     * One call to the court: the module's contract in this process, or court-ms over HTTP.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $files  by form field: one file, or a list of them
     */
    private function send(string $method, string $path, array $data = [], array $files = [], ?int $timeout = null, string $accept = 'application/json', bool $multipart = false): CourtAnswer
    {
        if (! $this->enabled()) {
            throw new CourtMsException('The court connection is not configured (COURT_MS_ENABLED; for a separate court-ms also COURT_MS_URL and COURT_MS_TOKEN).', retryable: false);
        }

        if ($this->inModule()) {
            return app(CourtIntegration::class)->call($method, $path, $data, $files);
        }

        $request = Http::baseUrl((string) config('court_ms.url'))
            ->withToken((string) config('court_ms.token'))
            ->accept($accept)
            ->timeout(max($timeout ?? 1, (int) config('court_ms.timeout', 20)))
            ->withOptions(['verify' => (bool) config('court_ms.verify_tls', true)]);

        try {
            if ($files === [] && ! $multipart) {
                $response = $method === 'GET' ? $request->get(self::API.$path, $data) : $request->post(self::API.$path, $data);
            } else {
                foreach ($files as $field => $file) {
                    $list = isset($file['contents']) ? [$file] : $file;
                    foreach ($list as $i => $one) {
                        $request = $request->attach(isset($file['contents']) ? $field : "{$field}[{$i}]", $one['contents'], $one['name'], ['Content-Type' => $one['mime'] ?? 'application/octet-stream']);
                    }
                }
                $response = $request->asMultipart()->post(self::API.$path, $this->multipartFields($data));
            }
        } catch (ConnectionException $e) {
            throw new CourtMsException('court-ms could not be reached: '.$e->getMessage());
        }

        return new CourtAnswer($response->status(), $response->body(), array_filter([
            'content-type' => $response->header('Content-Type'),
            'content-disposition' => $response->header('Content-Disposition'),
            'x-request-id' => $response->header('X-Request-ID'),
        ]));
    }

    /**
     * The PDFs of a referral or a filing as `documents`, their titles and sources beside them.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{name: string, contents: string, title: string, source?: string}>  $documents
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function withDocuments(array $payload, array $documents): array
    {
        if ($documents === []) {
            return [$payload, []];
        }
        $payload['document_titles'] = array_map(fn (array $d): string => $d['title'], $documents);
        $sources = array_filter(array_map(fn (array $d): ?string => $d['source'] ?? null, $documents), fn ($s) => $s !== null);
        if ($sources !== []) {
            $payload['document_sources'] = $sources;
        }

        return [$payload, ['documents' => array_map(fn (array $d): array => ['name' => $d['name'], 'contents' => $d['contents'], 'mime' => 'application/pdf'], $documents)]];
    }

    /**
     * Workspace files arrive by form name (`evidence_files[0]`): grouped into lists for the call.
     *
     * @param  array<string, array{name: string, contents: string, mime?: string}>  $files
     * @return array<string, mixed>
     */
    private function fileFields(array $files): array
    {
        $out = [];
        foreach ($files as $field => $file) {
            if (preg_match('/^([^\[]+)\[(\d+)\]$/', (string) $field, $m) === 1) {
                $out[$m[1]][(int) $m[2]] = $file;
            } else {
                $out[$field] = $file;
            }
        }
        foreach ($out as $field => $value) {
            if (! isset($value['contents'])) {
                ksort($value);
                $out[$field] = array_values($value);
            }
        }

        return $out;
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool} */
    private function page(array $json, int $since): array
    {
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
     * @return list<array{name: string, contents: string}>
     */
    private function multipartFields(array $payload): array
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

        return $fields;
    }

    /** @return array<string, mixed> */
    private function decode(CourtAnswer $answer): array
    {
        $json = $answer->json();
        if ($answer->successful()) {
            return $json;
        }

        $status = $answer->status;
        $message = (string) ($json['message'] ?? 'the court answered '.$status);

        throw new CourtMsException(
            "court-ms answered {$status}: {$message}",
            $status,
            $status >= 500 || in_array($status, [408, 429], true),
            (array) ($json['errors'] ?? []),
            isset($json['request_id']) ? (string) $json['request_id'] : $answer->header('X-Request-ID'),
            isset($json['error_code']) ? (string) $json['error_code'] : null,
        );
    }
}
