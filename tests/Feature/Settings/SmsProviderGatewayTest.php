<?php

declare(strict_types=1);

use App\Services\Sms\HttpSmsGateway;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** A settings double that answers the SMS group from $values and every other key with its default. */
function smsGatewayWith(array $values): HttpSmsGateway
{
    $settings = Mockery::mock(SystemSettingsService::class);
    $settings->shouldReceive('get')->andReturnUsing(
        fn (string $group, string $key, $default = null) => array_key_exists($key, $values) ? $values[$key] : $default,
    );

    return new HttpSmsGateway($settings);
}

test('Ethio Telecom sends through the SMPP gateway sendsms URL as UCS-2 for Amharic', function (): void {
    Http::fake(['10.0.0.5:13013/*' => Http::response('0: Accepted for delivery', 202)]);

    $sent = smsGatewayWith([
        'sms_provider' => 'ethio_telecom',
        'sms_api_url' => 'http://10.0.0.5:13013/cgi-bin/sendsms',
        'sms_account_id' => 'euisis',
        'sms_api_key' => 'bridge-secret',
        'sms_sender_id' => '8251',
    ])->send('0911 000 001', 'የማረጋገጫ ኮድ 123456');

    expect($sent)->toBeTrue();
    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'http://10.0.0.5:13013/cgi-bin/sendsms?')
            && $query['username'] === 'euisis'
            && $query['password'] === 'bridge-secret'
            && $query['from'] === '8251'
            && $query['to'] === '251911000001'
            && $query['text'] === 'የማረጋገጫ ኮድ 123456'
            && $query['charset'] === 'UTF-8'
            && $query['coding'] === '2';
    });
});

test('Ethio Telecom keeps plain English text in the default alphabet', function (): void {
    Http::fake(['*' => Http::response('0: Accepted for delivery', 202)]);

    smsGatewayWith([
        'sms_provider' => 'ethio_telecom',
        'sms_api_url' => 'http://10.0.0.5:13013/cgi-bin/sendsms',
        'sms_account_id' => 'euisis',
        'sms_api_key' => 'bridge-secret',
    ])->send('+251911000001', 'Code 123456');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'coding='));
});

test('Ethio Telecom is not configured without the gateway user name and password', function (): void {
    expect(smsGatewayWith([
        'sms_provider' => 'ethio_telecom',
        'sms_api_url' => 'http://10.0.0.5:13013/cgi-bin/sendsms',
    ])->isConfigured())->toBeFalse();
});

test('AfroMessage posts the identifier, sender and recipient with the token', function (): void {
    Http::fake([HttpSmsGateway::AFROMESSAGE_DEFAULT_URL => Http::response([
        'acknowledge' => 'success',
        'response' => ['status' => 'Send', 'message_id' => 'abc'],
    ])]);

    $sent = smsGatewayWith([
        'sms_provider' => 'afromessage',
        'sms_account_id' => 'e80ad9d8-0000',
        'sms_api_key' => 'afro-token',
        'sms_sender_id' => 'AAID',
    ])->send('0911000001', 'Code 123456');

    expect($sent)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === HttpSmsGateway::AFROMESSAGE_DEFAULT_URL
        && $request->hasHeader('Authorization', 'Bearer afro-token')
        && $request['from'] === 'e80ad9d8-0000'
        && $request['sender'] === 'AAID'
        && $request['to'] === '+251911000001'
        && $request['message'] === 'Code 123456');
});

test('AfroMessage counts an HTTP 200 error acknowledgement as not delivered', function (): void {
    Http::fake(['*' => Http::response(['acknowledge' => 'error', 'response' => ['errors' => ['Invalid sender']]])]);

    expect(smsGatewayWith([
        'sms_provider' => 'afromessage',
        'sms_api_key' => 'afro-token',
    ])->send('0911000001', 'Code 123456'))->toBeFalse();
});
