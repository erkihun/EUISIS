<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceErrorFingerprint;
use App\Services\Intelligence\IntelligenceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Groups unhandled exceptions by a safe fingerprint (brief §34–35): exception
 * class, the message with every variable part replaced, the route group and
 * the first application frame. One row per signature is counted up, so a
 * thousand identical failures are one fingerprint, and the stored sample
 * holds a relative file, a line and class names — never arguments, input,
 * headers, SQL parameters or a person.
 */
final class ErrorFingerprinter
{
    private const MESSAGE_LIMIT = 500;

    private const FRAMES = 5;

    public function __construct(private readonly IntelligenceSettings $settings) {}

    /** Records one occurrence. Never throws: the error page must still render. */
    public function record(Throwable $e, ?Request $request, ?string $errorId = null, int $status = 500): void
    {
        try {
            if (! $this->settings->telemetryEnabled()) {
                return;
            }
            if (! DB::connection()->getSchemaBuilder()->hasTable('intelligence_error_fingerprints')) {
                return;
            }

            $route = $request?->route();
            $routeName = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
            $group = RouteGroups::of($routeName, $request?->getPathInfo() ?? '/');
            $frame = $this->firstAppFrame($e);
            $message = self::normalize($e->getMessage());
            $fingerprint = sha1(implode('|', [get_class($e), $message, $group, $frame['file'] ?? '', (string) ($frame['line'] ?? '')]));
            $now = now();

            $updated = IntelligenceErrorFingerprint::query()->where('fingerprint', $fingerprint)->update([
                'occurrences' => DB::raw('occurrences + 1'),
                'last_seen_at' => $now,
                'last_error_id' => $errorId,
                'last_status' => $status,
                'resolved_at' => null,
                'updated_at' => $now,
            ]);

            if ($updated === 0) {
                IntelligenceErrorFingerprint::query()->create([
                    'fingerprint' => $fingerprint,
                    'exception_class' => mb_substr(get_class($e), 0, 190),
                    'message_normalized' => $message,
                    'route_name' => $routeName !== null ? mb_substr($routeName, 0, 190) : null,
                    'route_group' => $group,
                    'component' => $this->component($frame),
                    'last_status' => $status,
                    'last_error_id' => $errorId,
                    'sample_context' => [
                        'file' => $frame['file'] ?? $this->relative($e->getFile()),
                        'line' => $frame['line'] ?? $e->getLine(),
                        'frames' => $this->frames($e),
                        'method' => $request?->getMethod(),
                    ],
                    'occurrences' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }
        } catch (Throwable) {
            // Fingerprinting is telemetry; it never adds a failure to a failure.
        }
    }

    /**
     * Replaces the variable parts of a message (uuids, numbers, quoted values,
     * paths, e-mail-like tokens) so the same failure reads the same.
     */
    public static function normalize(string $message): string
    {
        $patterns = [
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '{uuid}',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '{email}',
            '/(?:[A-Za-z]:)?(?:[\\\\\/][\w.\-]+){2,}/' => '{path}',
            '/\'[^\']{0,200}\'/' => "'{value}'",
            '/"[^"]{0,200}"/' => '"{value}"',
            '/\b\d[\d,.]*\b/' => '{n}',
            '/\s+/' => ' ',
        ];
        $normalized = trim((string) preg_replace(array_keys($patterns), array_values($patterns), $message));

        return mb_substr($normalized === '' ? '(no message)' : $normalized, 0, self::MESSAGE_LIMIT);
    }

    /** @return array{file: string, line: int, class: ?string}|null */
    private function firstAppFrame(Throwable $e): ?array
    {
        $app = base_path('app');
        $candidates = [['file' => $e->getFile(), 'line' => $e->getLine(), 'class' => null], ...$e->getTrace()];
        foreach ($candidates as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && str_starts_with($file, $app)) {
                return ['file' => $this->relative($file), 'line' => (int) ($frame['line'] ?? 0), 'class' => isset($frame['class']) ? (string) $frame['class'] : null];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function frames(Throwable $e): array
    {
        $out = [];
        foreach ($e->getTrace() as $frame) {
            if (! isset($frame['class'])) {
                continue;
            }
            $out[] = $frame['class'].'::'.($frame['function'] ?? '');
            if (count($out) >= self::FRAMES) {
                break;
            }
        }

        return $out;
    }

    /** The application area of the first app frame: "Services/Intelligence", "Http/Controllers/Web"… */
    private function component(?array $frame): ?string
    {
        $file = $frame['file'] ?? null;
        if ($file === null || ! str_starts_with($file, 'app/')) {
            return null;
        }
        $parts = explode('/', substr($file, 4));
        array_pop($parts);

        return mb_substr(implode('/', array_slice($parts, 0, 2)), 0, 60) ?: null;
    }

    private function relative(string $file): string
    {
        $base = rtrim(base_path(), '\\/');
        $relative = str_starts_with($file, $base) ? substr($file, strlen($base) + 1) : $file;

        return str_replace('\\', '/', $relative);
    }
}
