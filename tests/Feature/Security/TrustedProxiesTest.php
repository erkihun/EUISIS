<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/_test/client-ip', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
    ]));
});

it('ignores forwarded headers when no proxy is configured', function (): void {
    config()->set('trustedproxy.proxies', null);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->getJson('/_test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '10.0.0.10', 'secure' => false]);
});

it('uses the client address and scheme a configured proxy forwards', function (): void {
    config()->set('trustedproxy.proxies', ['10.0.0.10']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->getJson('/_test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
});

it('does not trust forwarded headers from an unlisted peer', function (): void {
    config()->set('trustedproxy.proxies', ['10.0.0.10']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/_test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '198.51.100.7']);
});
