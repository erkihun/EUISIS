<?php

declare(strict_types=1);

use App\Logging\ErrorTrackerHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('parses a DSN into the Sentry-compatible store endpoint', function (): void {
    expect(ErrorTrackerHandler::parseDsn('https://abc123@glitchtip.example.gov.et/42'))
        ->toBe(['key' => 'abc123', 'store' => 'https://glitchtip.example.gov.et/api/42/store/'])
        ->and(ErrorTrackerHandler::parseDsn('http://k@tracker.internal:8000/sentry/7'))
        ->toBe(['key' => 'k', 'store' => 'http://tracker.internal:8000/sentry/api/7/store/'])
        ->and(ErrorTrackerHandler::parseDsn(null))->toBeNull()
        ->and(ErrorTrackerHandler::parseDsn('   '))->toBeNull()
        ->and(ErrorTrackerHandler::parseDsn('https://no-key.example/42'))->toBeNull()
        ->and(ErrorTrackerHandler::parseDsn('ftp://k@host/42'))->toBeNull()
        ->and(ErrorTrackerHandler::parseDsn('https://k@host/bad id'))->toBeNull();
});

it('posts an error with its exception to the tracker and keeps secrets out of the payload', function (): void {
    config()->set('logging.channels.error_tracker.with.dsn', 'https://abc123@glitchtip.example.gov.et/42');
    config()->set('intelligence.deployment.release', '1.0.0-pilot');
    Http::fake();

    Log::channel('error_tracker')->error('Boom', [
        'exception' => new RuntimeException('bad thing', 0, new LogicException('root cause')),
        'password' => 'hunter2',
        'api_token' => 'secret',
        'user' => 7,
        'request_id' => 'req-1',
    ]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $auth = $request->header('X-Sentry-Auth')[0] ?? '';
        $values = $request['exception']['values'];

        return $request->url() === 'https://glitchtip.example.gov.et/api/42/store/'
            && str_contains($auth, 'sentry_key=abc123')
            && str_contains($auth, 'sentry_version=7')
            && $request['level'] === 'error'
            && $request['message'] === 'Boom'
            && $request['release'] === '1.0.0-pilot'
            && $request['environment'] === 'testing'
            && $values[0]['type'] === LogicException::class
            && $values[1]['type'] === RuntimeException::class
            && $values[1]['value'] === 'bad thing'
            && $values[1]['stacktrace']['frames'] !== []
            && ! array_key_exists('password', $request['extra'])
            && ! array_key_exists('api_token', $request['extra'])
            && $request['extra']['user'] === 7
            && $request['extra']['request_id'] === 'req-1';
    });
});

it('stays silent without a DSN and below the configured level', function (): void {
    Http::fake();

    config()->set('logging.channels.error_tracker.with.dsn', null);
    Log::channel('error_tracker')->error('Nobody listens');
    Http::assertNothingSent();

    config()->set('logging.channels.error_tracker.with.dsn', 'https://abc123@glitchtip.example.gov.et/42');
    app('log')->forgetChannel('error_tracker');
    Log::channel('error_tracker')->warning('Below the threshold');
    Http::assertNothingSent();
});

it('never lets a failing tracker break the request', function (): void {
    config()->set('logging.channels.error_tracker.with.dsn', 'https://abc123@glitchtip.example.gov.et/42');
    Http::fake(fn () => throw new RuntimeException('tracker down'));

    Log::channel('error_tracker')->error('Still fine');

    expect(true)->toBeTrue();
});
