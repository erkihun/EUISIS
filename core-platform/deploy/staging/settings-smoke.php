<?php

declare(strict_types=1);

/*
 * The settings pages over real HTTPS against a deployed staging Core (rc1 rehearsal plan, stage B4/C3).
 *
 *   cd <release>/backend && php settings-smoke.php <https base url> <accounts.json> <fixtures dir> [ca.pem]
 *
 * accounts.json as for golden-path-smoke.php ("admin1" a city-wide administrator, "viewer" a
 * read-only account, both synthetic with MFA). Saves Settings › General (a PNG logo), Settings › Email
 * (mailer "log" and a throwaway password), sends the test e-mail; never touches Settings › Security.
 * Prints PASS lines; exits 1 at the first failure.
 */

use App\Services\CoreSettings;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

[$base, $accountsFile, $fixtures] = [rtrim($argv[1] ?? '', '/'), $argv[2] ?? '', rtrim($argv[3] ?? '', '/')];
$ca = $argv[4] ?? null;
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = json_decode((string) file_get_contents($accountsFile), true, 8, JSON_THROW_ON_ERROR);
$options = ['verify' => $ca ?? true];
$fail = function (string $what, ?Response $r = null): never {
    echo "  FAIL {$what}".($r ? ' — HTTP '.$r->status().' '.mb_substr($r->body(), 0, 200) : '')."\n";
    exit(1);
};
$pass = function (string $what): void { echo "  PASS {$what}\n"; };
$fresh = function (): CoreSettings { Cache::forget('core.settings.values'); return app(CoreSettings::class); };

$web = function (string $who) use ($base, &$config, $accountsFile, $options, $fail): array {
    $jar = new CookieJar;
    $client = fn () => Http::withOptions($options + ['cookies' => $jar, 'allow_redirects' => false])->baseUrl($base)->timeout(60);
    $xsrf = fn (): array => ['X-XSRF-TOKEN' => urldecode((string) $jar->getCookieByName('XSRF-TOKEN')?->getValue())];
    $client()->get('/login');
    $person = $config['accounts'][$who];
    $r = $client()->withHeaders($xsrf())->asForm()->post('/login', ['email' => $person['email'], 'password' => $person['password']]);
    ($r->status() === 302 && ! str_contains((string) $r->header('Location'), '/login')) || $fail("web sign-in of {$who}", $r);
    $code = array_shift($config['accounts'][$who]['recovery_codes']) ?? $fail("{$who} has no recovery code left");
    file_put_contents($accountsFile, json_encode($config, JSON_PRETTY_PRINT));
    $r = $client()->withHeaders($xsrf())->asForm()->post('/mfa/challenge', ['recovery_code' => $code]);
    ($r->status() === 302 && ! str_contains((string) $r->header('Location'), '/mfa/')) || $fail("second factor of {$who}", $r);

    return [$client, $xsrf];
};

echo "Settings smoke against {$base}\n";
[$viewer, $vxsrf] = $web('viewer');
$r = $viewer()->get('/system-settings');
in_array($r->status(), [403, 404], true) ? $pass('a viewer cannot open the settings ('.$r->status().')') : $fail('viewer opens settings', $r);
$r = $viewer()->withHeaders($vxsrf())->asForm()->post('/system-settings/general', ['_method' => 'patch', 'support_email' => 'x@example.org']);
in_array($r->status(), [403, 404], true) ? $pass('a viewer cannot change them ('.$r->status().')') : $fail('viewer changes settings', $r);

[$admin, $axsrf] = $web('admin1');
$r = $admin()->get('/system-settings');
($r->ok() && str_contains($r->body(), 'data-page')) ? $pass('an administrator opens the settings page') : $fail('settings page', $r);

$support = 'support-'.Str::lower(Str::random(6)).'@staging.invalid';
$r = $admin()->withHeaders($axsrf())->attach('identity_system_logo', (string) file_get_contents("{$fixtures}/photo.jpg"), 'logo.jpg')
    ->post('/system-settings/general', ['_method' => 'patch', 'support_email' => $support]);
$logo = $fresh()->get('general.identity_system_logo');
($r->status() === 302 && $fresh()->get('general.support_email') === $support && is_string($logo) && $logo !== '') ? $pass('General saved with a raster logo') : $fail('General save', $r);
$served = Http::withOptions($options)->get("{$base}/storage/{$logo}");
($served->ok() && $served->body() === file_get_contents("{$fixtures}/photo.jpg")) ? $pass('the logo is served from /storage over HTTPS (public disk linked by the release)') : $fail('logo not served', $served);

$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
$before = $fresh()->get('general.favicon');
$admin()->withHeaders($axsrf())->attach('favicon', $svg, 'icon.svg')->post('/system-settings/general', ['_method' => 'patch']);
$fresh()->get('general.favicon') === $before ? $pass('a scripted SVG favicon is refused') : $fail('SVG favicon stored');

$secret = 'Stg-'.Str::random(20);
$r = $admin()->withHeaders($axsrf())->asForm()->post('/system-settings/email', ['_method' => 'patch', 'mail_mailer' => 'log', 'mail_password' => $secret,
    'mail_from_address' => 'core-staging@staging.invalid']);
($r->status() === 302 && $fresh()->get('email.mail_password') === $secret) ? $pass('Email saved; the password is stored (encrypted)') : $fail('Email save', $r);
$stored = (string) DB::table('core_settings')->where('group', 'email')->where('key', 'mail_password')->value('value');
! str_contains($stored, $secret) ? $pass('the stored password is not plaintext') : $fail('plaintext password in core_settings');
$page = $admin()->get('/system-settings')->body();
! str_contains($page, $secret) ? $pass('the password is never sent back to the page') : $fail('password in the settings page');
$audit = DB::table('core_audit_logs')->where('action', 'settings.updated')->orderByDesc('created_at')->limit(5)->get(['old_values', 'new_values'])->toJson();
! str_contains($audit, $secret) ? $pass('the audit trail records [set], never the password') : $fail('password in the audit trail');

$r = $admin()->withHeaders($axsrf())->asForm()->post('/system-settings/test-email', ['recipient' => 'core-staging@staging.invalid']);
$tested = DB::table('core_audit_logs')->where('action', 'settings.channel_tested')->orderByDesc('created_at')->value('new_values');
($r->status() === 302 && str_contains((string) $tested, 'sent')) ? $pass('the test e-mail goes through the configured mailer (log) and is audited') : $fail('test e-mail', $r);

$r = $admin()->get('/core');
$r->ok() ? $pass('the screens still load with the new settings') : $fail('dashboard after settings', $r);
echo "Settings smoke passed\n";
