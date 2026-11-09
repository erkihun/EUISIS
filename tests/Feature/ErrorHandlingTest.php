<?php

declare(strict_types=1);

use App\Http\Middleware\RequestCorrelationId;
use App\Services\ErrorLoggingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function registerBombRoute(string $uri = 'test-bomb'): void
{
    Route::middleware('web')->get("/{$uri}", static function () {
        throw new RuntimeException('Internal database connection string: host=db.internal secret=abc123');
    });
}

// ── Correlation ID middleware ─────────────────────────────────────────────────

test('correlation id is added to response header', function () {
    $response = $this->get(route('login'));

    $response->assertHeader(RequestCorrelationId::HEADER);
});

test('valid incoming request id is echoed back', function () {
    $response = $this->withHeader(RequestCorrelationId::HEADER, 'my-custom-id-123')
        ->get(route('login'));

    $response->assertHeader(RequestCorrelationId::HEADER, 'my-custom-id-123');
});

test('invalid incoming request id is replaced with a uuid', function () {
    $response = $this->withHeader(RequestCorrelationId::HEADER, '<script>evil</script>')
        ->get(route('login'));

    $id = $response->headers->get(RequestCorrelationId::HEADER);
    expect($id)->not->toBe('<script>evil</script>');
    expect($id)->toMatch('/^[a-f0-9\-]{36}$/');
});

// ── 404 ───────────────────────────────────────────────────────────────────────

test('404 web returns inertia error page without stack trace', function () {
    $response = $this->get('/this-route-does-not-exist-at-all');

    $response->assertStatus(404);
    $content = $response->getContent();

    expect($content)->not->toContain('Stack trace')
        ->and($content)->not->toContain('Exception')
        ->and($content)->not->toContain('.php:');
});

test('404 api returns json not found message', function () {
    $response = $this->getJson('/api/this-does-not-exist');

    $response->assertStatus(404)
        ->assertJsonPath('message', __('errors.not_found'))
        ->assertJsonMissingPath('trace')
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('file');
});

// ── 403 ──────────────────────────────────────────────────────────────────────

test('403 api returns json forbidden message', function () {
    Route::middleware('web')->get('/test-403', static function () {
        abort(403);
    });

    $response = $this->getJson('/test-403');

    $response->assertStatus(403)
        ->assertJsonPath('message', __('errors.forbidden'))
        ->assertJsonMissingPath('exception');
});

// ── 401 ──────────────────────────────────────────────────────────────────────

test('unauthenticated api request returns 401 without leaking internals', function () {
    Route::middleware(['api', 'auth:sanctum'])->get('/api/test-protected', static fn () => response()->json(['ok' => true]));

    $response = $this->getJson('/api/test-protected');

    $response->assertStatus(401)
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');
});

// ── 429 ──────────────────────────────────────────────────────────────────────

test('429 api returns json rate limit message', function () {
    Route::middleware('web')->get('/test-429', static function () {
        abort(429);
    });

    $response = $this->getJson('/test-429');

    $response->assertStatus(429)
        ->assertJsonPath('message', __('errors.too_many_requests'))
        ->assertJsonMissingPath('exception');
});

// ── 500 / generic exceptions ──────────────────────────────────────────────────

test('api 500 does not expose exception message', function () {
    config(['app.debug' => false]);

    registerBombRoute('api-bomb');
    Route::middleware('api')->get('/api/api-bomb', static function () {
        throw new RuntimeException('Internal database connection string: host=db.internal secret=abc123');
    });

    $response = $this->getJson('/api/api-bomb');

    $response->assertStatus(500);
    $body = $response->getContent();

    expect($body)->not->toContain('db.internal')
        ->and($body)->not->toContain('abc123')
        ->and($body)->not->toContain('RuntimeException')
        ->and($body)->not->toContain('Stack trace')
        ->and($body)->not->toContain('.php:');
});

test('api 500 response contains error_id', function () {
    config(['app.debug' => false]);

    Route::middleware('api')->get('/api/bomb2', static function () {
        throw new RuntimeException('boom');
    });

    $response = $this->getJson('/api/bomb2');

    $response->assertStatus(500)
        ->assertJsonStructure(['message', 'error_id', 'status']);

    expect($response->json('error_id'))->toStartWith('ERR-');
});

test('stack trace is never in api response body', function () {
    config(['app.debug' => false]);

    Route::middleware('api')->get('/api/bomb3', static function () {
        throw new RuntimeException('boom');
    });

    $response = $this->getJson('/api/bomb3');

    expect($response->getContent())
        ->not->toContain('#0 ')   // stack frame notation
        ->not->toContain('Stack trace');
});

test('sql query is never in api response body', function () {
    config(['app.debug' => false]);

    Route::middleware('api')->get('/api/sql-bomb', static function () {
        throw new QueryException(
            'mysql',
            'SELECT * FROM secret_table WHERE password = "plain"',
            [],
            new PDOException('Access denied'),
        );
    });

    $response = $this->getJson('/api/sql-bomb');

    expect($response->getContent())
        ->not->toContain('secret_table')
        ->not->toContain('SELECT')
        ->not->toContain('password');
});

// ── ErrorLoggingService sanitization ─────────────────────────────────────────

test('sensitive fields are redacted from sanitized input', function () {
    $service = app(ErrorLoggingService::class);

    $sanitized = $service->sanitizeInput([
        'name' => 'Test User',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
        'token' => 'tok_abc123',
        'email' => 'test@example.com',
        'nested' => [
            'access_token' => 'bearer xyz',
            'value' => 'safe',
        ],
    ]);

    expect($sanitized['password'])->toBe('[REDACTED]')
        ->and($sanitized['password_confirmation'])->toBe('[REDACTED]')
        ->and($sanitized['token'])->toBe('[REDACTED]')
        ->and($sanitized['name'])->toBe('Test User')
        ->and($sanitized['email'])->toBe('test@example.com')
        ->and($sanitized['nested']['access_token'])->toBe('[REDACTED]')
        ->and($sanitized['nested']['value'])->toBe('safe');
});

test('large payloads are truncated in sanitized input', function () {
    $service = app(ErrorLoggingService::class);

    $big = str_repeat('A', 2000);
    $sanitized = $service->sanitizeInput(['data' => $big]);

    expect($sanitized['data'])->toStartWith('[TRUNCATED:');
});

test('error logging service writes to log and returns error id', function () {
    Log::shouldReceive('error')->once()->withArgs(function (string $msg, array $ctx) {
        return str_starts_with($msg, '[ERR-')
            && isset($ctx['error_id'])
            && isset($ctx['exception'])
            && isset($ctx['trace']);
    });

    $service = app(ErrorLoggingService::class);
    $errorId = $service->log(new RuntimeException('test error'));

    expect($errorId)->toStartWith('ERR-');
});

// ── Error translation files ───────────────────────────────────────────────────

test('english errors translation file exists and has required keys', function () {
    $keys = [
        'generic', 'api_generic', 'not_found', 'unauthorized',
        'forbidden', 'validation_failed', 'session_expired',
        'too_many_requests', 'service_unavailable', 'conflict',
        'id_card_error', 'verification_error',
    ];

    foreach ($keys as $key) {
        expect(__("errors.{$key}"))->not->toBe("errors.{$key}");
    }
});

test('amharic errors translation file exists and has required keys', function () {
    app()->setLocale('am');

    $keys = [
        'generic', 'api_generic', 'not_found', 'unauthorized',
        'forbidden', 'session_expired', 'too_many_requests',
    ];

    foreach ($keys as $key) {
        $translation = __("errors.{$key}");
        expect($translation)->not->toBe("errors.{$key}");
    }

    app()->setLocale('en');
});

test('validation errors still return field-level messages', function () {
    app()->setLocale('en');

    Route::middleware('web')->post('/test-validation', static function (Request $req) {
        $req->validate(['name' => 'required|string']);
    });

    $response = $this->postJson('/test-validation', []);

    $response->assertStatus(422)
        ->assertJsonPath('errors.name.0', 'The name field is required.');
});
