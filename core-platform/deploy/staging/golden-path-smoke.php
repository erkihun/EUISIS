<?php

declare(strict_types=1);

/*
 * The Golden Path over real HTTPS against a deployed staging Core
 * (docs/runbook/core-staging-rehearsal.md): organization → hierarchy version →
 * unit → position with seats → employee (draft, submit, verify by another,
 * photo, signature and a document uploaded and verified) → approval → card
 * request → approval by another → print batch → quality check by another →
 * issue → activate → credential verified by a partner's API client.
 *
 *   cd <release>/backend && php golden-path-smoke.php <https base url> <accounts.json> <fixtures dir> [ca.pem]
 *
 * accounts.json: {"root_organization_id", "accounts": {"admin1", "admin2", "registrar", "verifier",
 * "printer", "checker": {"email", "password", "totp_secret", "recovery_codes"}}} — synthetic
 * staging accounts with MFA; never real people, never production. Run from the
 * deployed release's backend/ (it boots that release to read ids it just made).
 * Prints PASS lines and exits 1 at the first failure.
 */

use App\Actions\EmployeeVerification;
use App\Actions\ManageDigitalCredentials;
use App\Models\CardPrintBatch;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;

[$base, $accountsFile, $fixtures] = [rtrim($argv[1] ?? '', '/'), $argv[2] ?? '', rtrim($argv[3] ?? '', '/')];
$ca = $argv[4] ?? null;
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = json_decode((string) file_get_contents($accountsFile), true, 8, JSON_THROW_ON_ERROR);
$people = $config['accounts'];
$run = strtoupper(Str::random(5));
$started = microtime(true);

$fail = function (string $what, ?Response $r = null): never {
    echo "  FAIL {$what}".($r ? ' — HTTP '.$r->status().' '.mb_substr($r->body(), 0, 300) : '')."\n";
    exit(1);
};
$pass = function (string $what): void { echo "  PASS {$what}\n"; };
$expect = function (Response $r, int $status, string $what) use ($fail, $pass): Response {
    $r->status() === $status ? $pass($what) : $fail($what, $r);

    return $r;
};
$options = ['verify' => $ca ?? true];
$api = function (string $who) use ($base, $people, $options, $fail): PendingRequest {
    static $tokens = [];
    if (! isset($tokens[$who])) {
        $code = (new Google2FA)->getCurrentOtp($people[$who]['totp_secret']);
        $r = Http::withOptions($options)->acceptJson()->post("{$base}/api/auth/login", ['email' => $people[$who]['email'], 'password' => $people[$who]['password'], 'code' => $code]);
        $r->ok() || $fail("API sign-in of {$who}", $r);
        $tokens[$who] = (string) $r->json('token');
    }

    return Http::withOptions($options)->acceptJson()->withToken($tokens[$who])->baseUrl("{$base}/api/v1")->timeout(60);
};
$web = function (string $who) use ($base, &$config, $accountsFile, $options, $fail): array {
    static $sessions = [];
    if (! isset($sessions[$who])) {
        $jar = new CookieJar;
        $client = fn () => Http::withOptions($options + ['cookies' => $jar, 'allow_redirects' => false])->baseUrl($base)->timeout(60);
        $xsrf = fn (): array => ['X-XSRF-TOKEN' => urldecode((string) $jar->getCookieByName('XSRF-TOKEN')?->getValue())];
        $client()->get('/login');
        $person = $config['accounts'][$who];
        $r = $client()->withHeaders($xsrf())->asForm()->post('/login', ['email' => $person['email'], 'password' => $person['password']]);
        ($r->status() === 302 && ! str_contains((string) $r->header('Location'), '/login')) || $fail("web sign-in of {$who}", $r);
        // Behind TLS the redirect must stay on https (TRUSTED_PROXIES, fixed in 2e8e8042).
        str_starts_with((string) $r->header('Location'), 'https://') || print("  NOTE the sign-in redirect is not https: ".$r->header('Location')."\n");
        // The second factor is asked for on the next request; answering it directly is the same step.
        // A recovery code works once: take it out of the accounts file as it is used.
        $code = array_shift($config['accounts'][$who]['recovery_codes']) ?? $fail("{$who} has no recovery code left");
        file_put_contents($accountsFile, json_encode($config, JSON_PRETTY_PRINT));
        $r = $client()->withHeaders($xsrf())->asForm()->post('/mfa/challenge', ['recovery_code' => $code]);
        ($r->status() === 302 && ! str_contains((string) $r->header('Location'), '/mfa/')) || $fail("second factor of {$who}", $r);
        $sessions[$who] = [$client, $xsrf];
    }

    return $sessions[$who];
};

echo "Golden Path smoke {$run} against {$base}\n";
$r = Http::withOptions($options)->get("{$base}/health/ready");
if ($r->status() === 404) { // a release before eca376af: Laravel's /up only
    $r = Http::withOptions($options)->get("{$base}/up");
    $r->ok() ? $pass('GET /up over HTTPS (this release has no /health/ready)') : $fail('GET /up', $r);
} else {
    ($r->ok() && $r->json('status') === 'ok') ? $pass('GET /health/ready over HTTPS') : $fail('GET /health/ready', $r);
}

// Organization and a governed hierarchy change (drafted by one administrator, approved and published by another).
$type = OrganizationType::query()->orderBy('created_at')->value('id');
$organization = $expect($api('admin1')->post('/organizations', ['organization_type_id' => $type, 'code' => "STG-{$run}", 'name_en' => "Staging office {$run}",
    'parent_organization_id' => $config['root_organization_id']]), 201, 'organization registered')->json('data.id');
$version = $expect($api('admin1')->post('/hierarchy-versions', ['version_name' => "Staging {$run}", 'effective_from' => now()->toDateString(), 'legal_basis' => 'Staging rehearsal (synthetic)']),
    201, 'hierarchy version drafted')->json('data.id');
$expect($api('admin1')->put("/hierarchy-versions/{$version}/parent", ['child_organization_id' => $organization, 'parent_organization_id' => $config['root_organization_id']]), 200, 'hierarchy change proposed');
$expect($api('admin1')->post("/hierarchy-versions/{$version}/review"), 200, 'hierarchy version sent for review');
$expect($api('admin1')->post("/hierarchy-versions/{$version}/approve"), 422, 'its drafter cannot approve it');
$expect($api('admin2')->post("/hierarchy-versions/{$version}/approve"), 200, 'another administrator approves it');
$expect($api('admin2')->post("/hierarchy-versions/{$version}/publish"), 200, 'hierarchy version published');

// Unit and an approved position with seats.
$unitType = OrganizationUnitType::query()->orderBy('created_at')->value('id');
$unit = $expect($api('admin1')->post('/organization-units', ['organization_id' => $organization, 'organization_unit_type_id' => $unitType, 'name_en' => "Services {$run}"]),
    201, 'organizational unit created')->json('data.id');
$position = $expect($api('admin1')->post('/positions', ['organization_id' => $organization, 'organization_unit_id' => $unit, 'title_en' => "Officer {$run}"]), 201, 'position created')->json('data.id');
$api('admin1')->post("/positions/{$position}/establishments", ['approved_slots' => 2, 'effective_from' => '2025-01-01', 'approval_reference' => "STG-EST-{$run}"])->successful()
    ? $pass('position seats approved') : $fail('position seats');

// Employee: registered, submitted, verified by someone else, evidence uploaded and verified, approved.
$employee = $expect($api('registrar')->post('/employees', ['first_name' => 'Staging', 'last_name' => "Person {$run}", 'date_of_birth' => '1990-01-01',
    'position_id' => $position, 'effective_from' => '2025-01-01']), 201, 'employee registered as a draft')->json('data.id');
$expect($api('registrar')->post("/employees/{$employee}/submit"), 200, 'employee submitted');
$checks = array_fill_keys([...EmployeeVerification::BASE_CHECKS, 'date_of_birth_checked', 'national_id_checked'], true);
$expect($api('registrar')->post("/employees/{$employee}/verify", ['checks' => $checks]), 422, 'the submitter cannot verify');
$expect($api('verifier')->post("/employees/{$employee}/verify", ['checks' => $checks]), 200, 'another officer verifies');
[$client, $xsrf] = $web('registrar');
foreach (['photo' => 'photo.jpg', 'signature' => 'signature.png', 'document' => 'letter.pdf'] as $kind => $file) {
    $r = $client()->withHeaders($xsrf())->attach('file', (string) file_get_contents("{$fixtures}/{$file}"), $file)
        ->post("/employees/{$employee}/documents", ['kind' => $kind] + ($kind === 'document' ? ['document_type' => EmployeeDocument::DOCUMENT_TYPES[0], 'title' => 'Appointment letter'] : []));
    $r->status() === 302 ? $pass("{$kind} uploaded over HTTPS") : $fail("{$kind} upload", $r);
}
[$vclient, $vxsrf] = $web('verifier');
foreach (EmployeeDocument::query()->where('employee_id', $employee)->where('is_current', true)->get() as $document) {
    $r = $vclient()->withHeaders($vxsrf())->asForm()->post("/employees/{$employee}/documents/{$document->id}/verify", []);
    ($r->status() === 302 && $document->refresh()->status === EmployeeDocument::VERIFIED) ? $pass("{$document->kind} verified by another officer") : $fail("{$document->kind} verification", $r);
}
$expect($api('admin1')->post("/employees/{$employee}/approve"), 200, 'employee approved and active');

// Card: requested, approved by another, printed in a batch, checked by another, issued, activated.
$request = $expect($api('registrar')->post("/employees/{$employee}/card-requests", ['request_type' => 'new']), 201, 'card requested')->json('data.id');
$expect($api('registrar')->post("/card-requests/{$request}/approve"), 422, 'the requester cannot approve');
$card = $expect($api('admin1')->post("/card-requests/{$request}/approve"), 200, 'card request approved')->json('data.id');
[$pclient, $pxsrf] = $web('printer');
$pclient()->withHeaders($pxsrf())->asForm()->post('/print-batches', ['card_ids' => [$card]]);
$batch = CardPrintBatch::query()->whereHas('items', fn ($q) => $q->where('id_card_id', $card))->latest('created_at')->first() ?? $fail('print batch created');
$pass('print batch created');
$r = $pclient()->withHeaders($pxsrf())->asForm()->post("/print-batches/{$batch->id}/mark-printed", []);
IdCard::query()->findOrFail($card)->printed_by !== null ? $pass('batch marked printed') : $fail('mark printed', $r);
$expect($api('printer')->post("/id-cards/{$card}/quality-check", ['result' => 'passed']), 422, 'the printer cannot pass its own quality check');
$expect($api('checker')->post("/id-cards/{$card}/quality-check", ['result' => 'passed']), 200, 'another officer passes the quality check');
$expect($api('admin1')->post("/id-cards/{$card}/issue"), 200, 'card issued');
$expect($api('admin1')->post("/id-cards/{$card}/activate"), 200, 'card activated');

// The credential, checked by a partner's API client limited to card checks.
$token = app(ManageDigitalCredentials::class)->tokenFor(IdCard::query()->findOrFail($card)) ?? $fail('credential issued on activation');
$client = $expect($api('admin1')->post('/api-clients', ['name' => "Staging verifier {$run}", 'scopes' => ['cards:verify'], 'scope_type' => 'citywide']), 201, 'partner API client created');
$secret = (string) $client->json('secret');
$r = Http::withOptions($options)->acceptJson()->withToken($secret)->post("{$base}/api/v1/integration/cards/verify", ['token' => $token]);
($r->ok() && $r->json('valid') === true && $r->json('holder') === null) ? $pass('the partner verifies the credential (no holder data returned)') : $fail('partner verification', $r);
$r = Http::withOptions($options)->acceptJson()->withToken($secret)->get("{$base}/api/v1/integration/employees");
$r->status() === 403 ? $pass('the partner cannot read employees (scope cards:verify only)') : $fail('partner scope', $r);

printf("Golden Path smoke passed in %.1f s (employee %s, card %s)\n", microtime(true) - $started, $employee, $card);
