<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Sends error-level log records to a Sentry-compatible error tracker
 * (GlitchTip, self-hosted Sentry, Bugsink) over its `store` endpoint.
 *
 * Dependency-free on purpose: the application must keep working, and keep
 * logging locally, when the tracker is down, unreachable or not configured.
 * Only the exception type, message, stack frames (file, line, function) and
 * scalar log context reach the tracker; request bodies, headers, session data
 * and anything that looks like a secret never leave the host.
 */
final class ErrorTrackerHandler extends AbstractProcessingHandler
{
    private const CLIENT = 'agelgay-php/1.0';

    private const MAX_FRAMES = 50;

    private const MAX_STRING = 1000;

    private const SECRET_KEY = '/pass|secret|token|otp|key|authorization|cookie|session|credential/i';

    /** @var array{key: string, store: string}|null */
    private readonly ?array $dsn;

    /** Guards against a tracker failure being logged and sent to the tracker again. */
    private static bool $sending = false;

    public function __construct(
        ?string $dsn,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        private readonly float $timeout = 3.0,
    ) {
        parent::__construct($level, $bubble);
        $this->dsn = self::parseDsn($dsn);
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->dsn !== null && parent::isHandling($record);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->dsn === null || self::$sending) {
            return;
        }

        self::$sending = true;
        try {
            Http::withHeaders([
                'X-Sentry-Auth' => sprintf('Sentry sentry_version=7, sentry_client=%s, sentry_key=%s', self::CLIENT, $this->dsn['key']),
            ])
                ->connectTimeout(2)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->dsn['store'], self::event($record));
        } catch (Throwable) {
            // The tracker is an observer, never a dependency: swallow and move on.
        } finally {
            self::$sending = false;
        }
    }

    /**
     * Parses `https://<key>@<host>[:port][/prefix]/<project-id>` into the
     * store endpoint. Returns null (tracker off) for anything else.
     *
     * @return array{key: string, store: string}|null
     */
    public static function parseDsn(?string $dsn): ?array
    {
        $dsn = trim((string) $dsn);
        if ($dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            return null;
        }
        if (! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $projectId = basename(rtrim($parts['path'], '/'));
        if ($projectId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $projectId) !== 1) {
            return null;
        }

        $prefix = rtrim(str_replace('\\', '/', dirname(rtrim($parts['path'], '/'))), '/');
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return [
            'key' => $parts['user'],
            'store' => $base.$prefix.'/api/'.$projectId.'/store/',
        ];
    }

    /**
     * The Sentry event document for a log record.
     *
     * @return array<string, mixed>
     */
    public static function event(LogRecord $record): array
    {
        $exception = $record->context['exception'] ?? null;

        $event = [
            'event_id' => str_replace('-', '', (string) Str::uuid()),
            'timestamp' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'platform' => 'php',
            'level' => self::sentryLevel($record->level),
            'logger' => $record->channel,
            'message' => Str::limit($record->message, self::MAX_STRING, ''),
            'release' => self::nullable(config('intelligence.deployment.release')),
            'environment' => app()->environment(),
            'server_name' => self::nullable(gethostname() ?: null),
            'extra' => self::safeContext($record->context),
        ];

        if ($exception instanceof Throwable) {
            $event['exception'] = ['values' => self::chain($exception)];
        }

        return array_filter($event, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Innermost cause first, as the Sentry protocol expects.
     *
     * @return list<array<string, mixed>>
     */
    private static function chain(Throwable $exception): array
    {
        $values = [];
        for ($current = $exception, $depth = 0; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
            $values[] = [
                'type' => $current::class,
                'value' => Str::limit($current->getMessage(), self::MAX_STRING, ''),
                'stacktrace' => ['frames' => self::frames($current)],
            ];
        }

        return array_reverse($values);
    }

    /**
     * Oldest frame first; the throwing line is the last frame.
     *
     * @return list<array<string, mixed>>
     */
    private static function frames(Throwable $exception): array
    {
        $trace = array_slice($exception->getTrace(), 0, self::MAX_FRAMES - 1);
        $frames = [];
        foreach (array_reverse($trace) as $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : null;
            $frames[] = array_filter([
                'filename' => $file !== null ? self::relative($file) : null,
                'lineno' => $frame['line'] ?? null,
                'function' => isset($frame['function']) ? (($frame['class'] ?? '').($frame['type'] ?? '').$frame['function']) : null,
                'in_app' => $file !== null ? ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) : null,
            ], static fn (mixed $value): bool => $value !== null);
        }
        $frames[] = [
            'filename' => self::relative($exception->getFile()),
            'lineno' => $exception->getLine(),
            'in_app' => ! str_contains($exception->getFile(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR),
        ];

        return $frames;
    }

    private static function relative(string $file): string
    {
        $base = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Scalar context only, secrets by key name dropped, strings bounded.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception' || preg_match(self::SECRET_KEY, (string) $key) === 1) {
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = Str::limit($value, self::MAX_STRING, '');
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } elseif ($value instanceof \BackedEnum) {
                $safe[$key] = $value->value;
            } elseif ($value instanceof \Stringable) {
                $safe[$key] = Str::limit((string) $value, self::MAX_STRING, '');
            }
        }

        return $safe;
    }

    private static function sentryLevel(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info, Level::Notice => 'info',
            Level::Warning => 'warning',
            Level::Error => 'error',
            Level::Critical, Level::Alert, Level::Emergency => 'fatal',
        };
    }

    private static function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
