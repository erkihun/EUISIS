<?php

declare(strict_types=1);

namespace Modules\Court\Contracts;

use Modules\Court\Contracts\Data\CourtAnswer;

/**
 * What EUISIS asks of the court (ADR 0020): referrals and their updates, an
 * employee's court summary, filings, the employee's case workspace, case
 * parties, institutions' cases and appeals, letters. The operations are the
 * court's integration actions (Flows 1–7, court-ms docs/euisis-integration.md),
 * named by their path under /api/v1/integration, and they answer in the same
 * process: no URL, token or network.
 */
interface CourtIntegration
{
    /**
     * @param  'GET'|'POST'  $method
     * @param  string  $path  e.g. '/referrals', '/people/cases/12/workspace'
     * @param  array<string, mixed>  $data
     * @param  array<string, array{name: string, contents: string, mime?: string}|list<array{name: string, contents: string, mime?: string}>>  $files  by form field
     */
    public function call(string $method, string $path, array $data = [], array $files = []): CourtAnswer;
}
