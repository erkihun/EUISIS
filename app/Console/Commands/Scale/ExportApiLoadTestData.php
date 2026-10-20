<?php

declare(strict_types=1);

namespace App\Console\Commands\Scale;

use App\Models\ExternalApplication;
use App\Models\ExternalApplicationOrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the input file for the partner-API k6 scripts (loadtest/k6/partner-api.js):
 * synthetic API clients with fresh tokens, and a random sample of synthetic
 * employees with their card identifiers. Benchmark databases only — it refuses
 * production and any database holding non-synthetic employees, because it
 * creates API clients and raises the API rate limit setting.
 *
 * The raw card token of synthetic card SC-C-{n} is "scale-{n}" (scale:generate
 * stores its SHA-256), so the file can carry presentable "{card id}|{raw}" tokens.
 */
class ExportApiLoadTestData extends Command
{
    protected $signature = 'scale:export-api-loadtest-data
        {path : Where to write the JSON file (keep it outside the repository: it holds bearer tokens)}
        {--clients=20 : City-wide API clients (load spread like many partners)}
        {--scoped-clients=4 : Clients limited to one organization subtree}
        {--sample=20000 : Employees to sample}
        {--rate-limit=10000 : Requests per minute per client (per-client and global API limiter)}';

    protected $description = 'Create synthetic API clients and export partner-API k6 input data (benchmark databases only).';

    public function handle(): int
    {
        if (app()->environment('production') || ! app()->environment(['local', 'testing', 'benchmark', 'staging'])) {
            $this->error('Never against production.');

            return self::FAILURE;
        }
        if (DB::table('employees')->where('employee_number', 'not like', 'SC-%')->limit(1)->exists()) {
            $this->error('Database '.DB::getDatabaseName().' holds non-synthetic employees; use a separate benchmark database.');

            return self::FAILURE;
        }

        $rate = max(60, (int) $this->option('rate-limit'));
        $providerId = DB::table('service_providers')->where('code', 'like', 'SC-SP-%')->orderBy('created_at')->value('id');
        $providerCode = DB::table('service_providers')->where('id', $providerId)->value('code');
        if ($providerId === null) {
            $this->error('No synthetic service provider; run scale:generate first.');

            return self::FAILURE;
        }

        DB::table('system_settings')->where('group', 'security')->where('key', 'api_rate_limit_per_minute')->update(['value' => (string) $rate]);
        Cache::flush();

        $scopes = ['employees.basic_read', 'employee_assignments.read', 'organizations.read', 'organization_units.read', 'positions.read',
            'organization_structure.read', 'id_cards.verify', 'service_eligibility.check', 'service_transactions.create', 'changes.read'];
        $organizations = DB::table('organizations')->where('code', 'like', 'SC-%')
            ->whereExists(fn ($q) => $q->from('positions')->whereColumn('positions.organization_id', 'organizations.id'))
            ->orderBy('code')->pluck('id')->all();

        $clients = [];
        $total = (int) $this->option('clients') + (int) $this->option('scoped-clients');
        for ($i = 0; $i < $total; $i++) {
            $scoped = $i >= (int) $this->option('clients');
            $application = ExternalApplication::query()->updateOrCreate(['code' => sprintf('SC-K6-%02d', $i)], [
                'name' => sprintf('k6 synthetic client %02d', $i), 'status' => 'active', 'allowed_scopes' => $scopes,
                'rate_limit_per_minute' => $rate, 'allowed_ips' => [], 'organization_access' => $scoped ? 'scoped' : 'city',
                'service_provider_id' => $providerId,
            ]);
            $application->forceFill(['unrestricted_endpoints' => true])->save();
            $application->tokens()->delete();
            $organization = null;
            if ($scoped) {
                $organization = $organizations[$i % count($organizations)];
                ExternalApplicationOrganizationScope::query()->where('external_application_id', $application->id)->delete();
                ExternalApplicationOrganizationScope::query()->create(['external_application_id' => $application->id, 'organization_id' => $organization, 'scope_type' => 'self']);
            }
            $clients[] = ['code' => $application->code, 'scoped' => $scoped, 'organizationId' => $organization,
                'token' => $application->createToken('k6', $application->grantableScopes())->plainTextToken];
        }

        // A random sample spread over the whole table (TABLESAMPLE reads ~sample rows, not 3M).
        $population = max(1, (int) DB::table('employees')->count());
        $percent = min(100, max(0.01, ((int) $this->option('sample') * 1.5 / $population) * 100));
        $rows = DB::select("
            SELECT e.id, e.employee_number, a.organization_id, a.organization_unit_id, a.position_id, c.id AS card_id, c.card_number, c.public_card_uuid
            FROM employees e TABLESAMPLE SYSTEM ({$percent})
            JOIN employee_assignments a ON a.id = e.current_assignment_id AND a.is_current
            JOIN id_cards c ON c.employee_id = e.id AND c.is_current AND c.status = 'active'
            WHERE e.employee_number LIKE 'SC-%' AND e.status = 'active' AND c.card_number LIKE 'SC-C-%'
            LIMIT ".(int) $this->option('sample'));

        $data = [
            'providerCode' => $providerCode,
            'serviceType' => 'cafeteria',
            'clients' => $clients,
            'employees' => array_map(fn ($r): array => [
                'id' => $r->id, 'number' => $r->employee_number, 'org' => $r->organization_id, 'unit' => $r->organization_unit_id,
                'position' => $r->position_id, 'cardUuid' => $r->public_card_uuid,
                'cardToken' => $r->card_id.'|scale-'.Str::after($r->card_number, 'SC-C-'),
            ], $rows),
            'organizations' => array_slice($organizations, 0, 200),
        ];

        file_put_contents($this->argument('path'), json_encode($data, JSON_UNESCAPED_SLASHES));
        $this->info(sprintf('Wrote %d clients (%d scoped) and %d sampled employees; API limit set to %d/min per client.',
            count($clients), (int) $this->option('scoped-clients'), count($data['employees']), $rate));

        return self::SUCCESS;
    }
}
