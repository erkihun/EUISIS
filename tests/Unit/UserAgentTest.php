<?php

declare(strict_types=1);

use App\Support\UserAgent;

it('names the browser, the operating system and the kind of device', function (string $agent, ?string $browser, ?string $os, string $device): void {
    expect(UserAgent::summarize($agent))->toBe(['browser' => $browser, 'os' => $os, 'device' => $device]);
})->with([
    'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'desktop'],
    'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0', 'Edge', 'Windows', 'desktop'],
    'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'phone'],
    'Chrome on Android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android', 'phone'],
    'Chrome on Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'Chrome', 'Android', 'tablet'],
    'Safari on iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'tablet'],
    'Firefox on Linux' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', 'Linux', 'desktop'],
    'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', 'Safari', 'macOS', 'desktop'],
    'headless Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'desktop'],
    'a script' => ['curl/8.4.0', null, null, 'unknown'],
    'nothing' => ['', null, null, 'unknown'],
]);

it('labels a device the way a person says it', function (): void {
    expect(UserAgent::label('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'))->toBe('Chrome on Windows')
        ->and(UserAgent::label('Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101'))->toBe('Linux')
        ->and(UserAgent::label(null))->toBeNull();
});
