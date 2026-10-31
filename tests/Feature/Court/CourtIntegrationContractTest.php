<?php

declare(strict_types=1);

use App\Services\CourtMs\CourtMsClient;
use App\Services\CourtMs\CourtMsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Court\Domain\Models\EuisisReferral;
use Modules\Court\Domain\Models\IntegrationClient;

/*
| ADR 0020: EUISIS asks the court module directly, through its CourtIntegration
| contract, as it asks any module (Grievance, Discipline…). No URL, token or
| request leaves the application (Http::preventStrayRequests).
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('court_private');
    config(['court_ms.enabled' => true, 'court_ms.url' => '', 'court_ms.token' => null]);
});

function contractReferral(int $caseTypeId): array
{
    return [
        'referral_id' => (string) Str::uuid(),
        'grievance_reference' => 'GRV-2610-0009',
        'tribunal_case_number' => 'TRB-GRV-2610-0009',
        'case_type_id' => $caseTypeId,
        'title' => 'Promotion decision contested',
        'summary' => 'The complainant contests a promotion decision.',
        'complainant' => ['name' => 'Abebe Kebede', 'employee_uuid' => (string) Str::uuid(), 'phone' => '0911000000'],
        'respondent' => ['name' => 'Bole Sub-city Administration', 'organization_uuid' => (string) Str::uuid()],
        'referring_officer' => 'Grievance officer',
    ];
}

test('EUISIS talks to the court module directly, with no URL or token', function (): void {
    $client = app(CourtMsClient::class);

    expect($client->enabled())->toBeTrue()->and($client->inModule())->toBeTrue();
});

test('a grievance referral opens one court case through the contract, with its document, however often it is sent', function (): void {
    $type = DB::table('court_case_types')->insertGetId(['name' => 'Administrative', 'prefix' => 'ADM', 'created_at' => now(), 'updated_at' => now()]);
    $client = app(CourtMsClient::class);
    $payload = contractReferral($type);

    expect(collect($client->caseTypes())->pluck('id')->all())->toContain($type);

    $first = $client->refer($payload, [['name' => 'decision.pdf', 'contents' => "%PDF-1.4\n%%EOF\n", 'title' => 'Committee decision']]);
    $again = $client->refer($payload);

    $case = DB::table('court_cases')->sole();
    expect($first['case_number'])->toStartWith('ADM/')
        ->and($again['case_number'])->toBe($first['case_number'])
        ->and($case->euisis_referral_id)->toBe($payload['referral_id'])
        ->and(DB::table('court_case_evidences')->where('case_id', $case->id)->count())->toBe(1)
        ->and(EuisisReferral::query()->sole()->integration_client_id)->toBe(IntegrationClient::query()->where('name', IntegrationClient::IN_PROCESS_NAME)->value('id'));

    expect($client->updates(0)['data'])->toBeArray();
});

test('the court refuses what it would refuse over the API, and EUISIS sees why', function (): void {
    $client = app(CourtMsClient::class);

    expect(fn () => $client->refer(['referral_id' => (string) Str::uuid()]))
        ->toThrow(fn (CourtMsException $e) => expect($e->status)->toBe(422));
});

test('nobody outside reaches the court as EUISIS: the integration API still wants a token', function (): void {
    $this->getJson('/court/api/v1/integration/case-types')->assertUnauthorized();
});
