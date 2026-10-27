<?php

declare(strict_types=1);

/*
 * The ID card template designer over real HTTPS against a deployed staging Core (rehearsal stage B4/C3,
 * from the candidate that carries the EUISIS designer on).
 *
 *   cd <release>/backend && php card-designer-smoke.php <https base url> <accounts.json> <fixtures dir> [ca.pem]
 *
 * accounts.json as for golden-path-smoke.php ("admin1" a city-wide administrator, "viewer" a read-only
 * account, both synthetic with MFA). Saves one active default template with PNG artwork (the artwork
 * must then survive a backup and restore: core-restore-drill.sh checks it), proves the artwork is
 * fetched only by people who work with cards, that a disguised JPG is refused, and that the saved
 * template became the active print version. Prints PASS lines; exits 1 at the first failure.
 */

use App\Models\CardTemplateVersion;
use App\Models\IdCardTemplate;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

$page = '/system-settings/id-card-templates';
$png = (string) file_get_contents("{$fixtures}/signature.png");
$code = 'stg-'.Str::lower(Str::random(6));
$fields = fn (array $extra = []) => $extra + ['name' => "Staging {$code}", 'code' => $code, 'orientation' => 'portrait', 'status' => 'active', 'is_default' => '1',
    'employee_fields' => ['name', 'idNumber']];

echo "Card designer smoke against {$base}\n";
[$viewer, $vxsrf] = $web('viewer');
$r = $viewer()->get($page);
$r->ok() ? $pass('a viewer sees the templates (everyone who sees cards does)') : $fail('viewer opens the designer', $r);
$r = $viewer()->withHeaders($vxsrf())->attach('front_background', $png, 'front.png')->post($page, $fields());
in_array($r->status(), [403, 404], true) ? $pass('a viewer cannot save a template ('.$r->status().')') : $fail('viewer saves a template', $r);

[$admin, $axsrf] = $web('admin1');
$r = $admin()->get($page);
($r->ok() && str_contains($r->body(), 'data-page')) ? $pass('an administrator opens the designer') : $fail('designer page', $r);
$r = $admin()->get('/core/card-templates');
($r->status() === 302 && str_contains((string) $r->header('Location'), $page)) ? $pass('the old Core page sends people to the designer') : $fail('/core/card-templates', $r);

$before = IdCardTemplate::query()->count();
$r = $admin()->withHeaders($axsrf())->attach('front_background', (string) file_get_contents("{$fixtures}/photo.jpg"), 'front.png')->post($page, $fields());
(IdCardTemplate::query()->count() === $before && $r->status() === 302) ? $pass('a JPG disguised as PNG artwork is refused') : $fail('disguised JPG stored', $r);

$r = $admin()->withHeaders($axsrf())->attach('front_background', $png, 'front.png')->attach('logo_primary', $png, 'logo.png')->post($page, $fields());
$template = IdCardTemplate::query()->where('code', $code)->first();
($r->status() === 302 && $template !== null && $template->is_default && $template->status === 'active') ? $pass("template {$code} saved as the active default") : $fail('template save', $r);
$path = (string) $template->front_background_path;
(str_starts_with($path, 'id-card-templates/') && Storage::disk('local')->exists($path) && Storage::disk('local')->get($path) === $png)
    ? $pass("the artwork is on the local disk at {$path}") : $fail('artwork not stored');

$r = $admin()->get("{$page}/{$template->id}/background/front");
($r->ok() && $r->header('Content-Type') === 'image/png' && $r->body() === $png) ? $pass('the artwork is served to a template manager') : $fail('artwork fetch', $r);
$stranger = Http::withOptions($options + ['allow_redirects' => false])->baseUrl($base)->get("{$page}/{$template->id}/background/front");
$stranger->status() !== 200 ? $pass('the artwork is refused without a session ('.$stranger->status().')') : $fail('artwork served to a stranger', $stranger);

$version = CardTemplateVersion::query()->where('status', CardTemplateVersion::ACTIVE)->first();
($version !== null && DB::table('card_templates')->where('id', $version->card_template_id)->value('code') === $code)
    ? $pass('the saved template is the active print version (mirrored)') : $fail('mirror did not activate a version');
DB::table('core_audit_logs')->where('action', 'card_template.mirrored')->exists() ? $pass('the mirror is audited') : $fail('no mirror audit entry');

foreach (['/id-cards', '/card-requests', '/print-batches'] as $p) {
    $r = $admin()->get($p);
    in_array($r->status(), [200, 302], true) ? $pass("{$p} still answers (".$r->status().')') : $fail("{$p} after the template change", $r);
}
echo "Card designer smoke passed\n";
