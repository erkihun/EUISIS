<?php

declare(strict_types=1);

/*
 * The application-level checks of a restore drill (core-restore-drill.sh step 8):
 * the restored copy, served at <base-url>, is used the way people use it.
 *
 *   php restore-verify.php <base-url> <credentials.json> <snapshot.json>
 *
 * Run from the restored release's backend/ directory (it boots that release to
 * pick sample records). credentials.json: {"email", "password", "totp_secret",
 * "recovery_code"} of a city-wide drill administrator that exists in the backup;
 * keep it with the restore operator, never in the repository. Prints PASS lines;
 * exits 1 at the first failure.
 */

use App\Actions\ManageDigitalCredentials;
use App\Models\IdCard;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FAQRCode\Google2FA;

[$base, $credsFile] = [rtrim($argv[1] ?? '', '/'), $argv[2] ?? ''];
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$creds = json_decode((string) file_get_contents($credsFile), true, 4, JSON_THROW_ON_ERROR);

$pass = function (string $what): void { echo "  PASS {$what}\n"; };
$fail = function (string $what): never { echo "  FAIL {$what}\n"; exit(1); };
$check = fn (bool $ok, string $what) => $ok ? $pass($what) : $fail($what);

// Sample records from the restored database: an employee with a current placement, an active card with an
// active credential, and an employee document with its stored checksum.
$employee = DB::table('employees')->whereNotNull('current_assignment_id')->orderBy('employee_number')->first(['id', 'employee_number', 'current_assignment_id']);
$card = IdCard::query()->where('status', IdCard::ACTIVE)->whereHas('employee')
    ->whereExists(fn ($q) => $q->from('digital_credentials')->whereColumn('digital_credentials.id_card_id', 'id_cards.id')->where('status', 'active'))->first();
$document = DB::table('employee_documents')->where('is_current', true)->orderBy('created_at')->first(['id', 'employee_id', 'checksum']);
$check($employee !== null && $card !== null && $document !== null, 'restored data holds employees with placements, active cards with credentials and documents');

$http = fn (CookieJar $jar) => Http::withOptions(['cookies' => $jar, 'allow_redirects' => false])->timeout(30)->baseUrl($base);
$xsrf = fn (CookieJar $jar): string => urldecode((string) $jar->getCookieByName('XSRF-TOKEN')?->getValue());

$r = Http::timeout(30)->get("{$base}/health/ready");
if ($r->status() === 404) { // a release before eca376af has Laravel's /up only
    $check(Http::timeout(30)->get("{$base}/up")->ok(), 'GET /up: the restored application boots (this release has no /health/ready)');
} else {
    $check($r->ok() && $r->json('status') === 'ok', 'GET /health/ready: database, cache, queue store and storage answer');
}

// The screens: sign-in with the second factor, as an administrator does.
$jar = new CookieJar;
$r = $http($jar)->get('/login');
$check($r->ok() && str_contains($r->body(), 'data-page'), 'GET /login renders the sign-in screen');
$r = $http($jar)->withHeaders(['X-XSRF-TOKEN' => $xsrf($jar)])->asForm()->post('/login', ['email' => $creds['email'], 'password' => $creds['password']]);
$check($r->status() === 302 && ! str_contains((string) $r->header('Location'), '/login'), 'password accepted');
$r = $http($jar)->get('/employees');
$check($r->status() === 302 && str_contains((string) $r->header('Location'), '/mfa/challenge'), 'the screens ask for the second factor before anything else');
$code = (new Google2FA)->getCurrentOtp($creds['totp_secret']);
$r = $http($jar)->withHeaders(['X-XSRF-TOKEN' => $xsrf($jar)])->asForm()->post('/mfa/challenge', ['code' => $code]);
$check($r->status() === 302 && ! str_contains((string) $r->header('Location'), '/mfa/'), 'authenticator code accepted: the administrator is signed in');
$r = $http($jar)->get("/employees/{$employee->id}");
$check($r->ok() && str_contains($r->body(), $employee->employee_number), "employee {$employee->employee_number} opens on the screens");
$r = $http($jar)->get("/employees/{$document->employee_id}/documents/{$document->id}/file");
$check($r->ok() && hash('sha256', $r->body()) === $document->checksum, 'an employee document downloads for an authorized user and matches its stored checksum');
$stranger = new CookieJar;
$r = $http($stranger)->get("/employees/{$document->employee_id}/documents/{$document->id}/file");
$check(in_array($r->status(), [302, 401, 403, 404], true) && $r->status() !== 200, 'the same document is refused without a session ('.$r->status().')');

// The API, as integrations and the screens' API clients use it.
$r = Http::timeout(30)->acceptJson()->post("{$base}/api/auth/login", ['email' => $creds['email'], 'password' => $creds['password'], 'recovery_code' => $creds['recovery_code']]);
$check($r->ok() && is_string($r->json('token')), 'API sign-in with a recovery code issues a token');
$api = Http::timeout(30)->acceptJson()->withToken((string) $r->json('token'))->baseUrl("{$base}/api/v1");
$total = DB::table('employees')->count();
$r = $api->get('/employees', ['per_page' => 1]);
$check($r->ok() && (int) $r->json('total') === $total, "the employee list reports all {$total} restored employees");
$r = $api->get("/employees/{$employee->id}");
$check($r->ok() && str_contains($r->body(), (string) $employee->current_assignment_id), 'the employee record carries its current placement');
$r = $api->get('/hierarchy');
$check($r->ok() && strlen($r->body()) > 20, 'the organization hierarchy loads ('.strlen($r->body()).' bytes)');
$r = $api->post('/cards/verify', ['card_uuid' => $card->public_card_uuid]);
$check($r->ok() && $r->json('valid') === true, 'an active card verifies by its UUID');
$token = app(ManageDigitalCredentials::class)->tokenFor($card);
$check($token !== null, 'the card credential decrypts with the restored application key');
$r = $api->post('/cards/verify', ['token' => $token]);
$check($r->ok() && $r->json('valid') === true, 'the digital credential verifies with the restored credential keys');
$r = $api->post('/cards/verify', ['token' => strrev((string) $token)]);
$check($r->json('valid') !== true, 'a forged credential is refused');

// Settings (from rc1 on): the encrypted secrets need the restored APP_KEY, the images the public disk.
$settings = app(App\Services\CoreSettings::class);
if (method_exists($settings, 'configured')) {
    if ($settings->configured('email.mail_password')) {
        $password = $settings->get('email.mail_password');
        $check(is_string($password) && $password !== '', 'the encrypted mail password decrypts with the restored application key');
    }
    $logo = $settings->get('general.identity_system_logo');
    if (is_string($logo) && $logo !== '') {
        $check(Illuminate\Support\Facades\Storage::disk('public')->exists($logo) && Http::timeout(30)->get("{$base}/storage/{$logo}")->ok(),
            'the settings logo is restored and served from /storage');
    }
}
echo "  all application checks passed\n";
