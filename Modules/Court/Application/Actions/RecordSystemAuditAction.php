<?php

declare(strict_types=1);

namespace Modules\Court\Application\Actions;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\SystemSetting;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final readonly class RecordSystemAuditAction
{
    private const SENSITIVE_KEY_PARTS = [
        'password', 'passwd', 'secret', 'token', 'otp', 'authorization',
        'cookie', 'signature', 'private_key', 'api_key',
    ];

    public function execute(Request $request, ?int $responseStatus = null, ?Throwable $exception = null): void
    {
        $settings = SystemSetting::cached();

        if ($settings !== null && ! $settings->audit_logging_enabled) {
            return;
        }

        if ($request->attributes->getBoolean('system_audit_recorded')) {
            return;
        }

        $request->attributes->set('system_audit_recorded', true);

        [$actorType, $actorId] = $this->resolveActor($request);
        $routeName = $request->route()?->getName();
        $routeTemplate = $request->route()?->uri() ?? $request->path();
        $status = $responseStatus ?? $this->statusFor($exception);
        $action = $routeName ?: sprintf('%s %s', $request->method(), $routeTemplate);

        $payload = [
            'request_id' => $request->attributes->get('request_id'),
            'user_id' => $actorId,
            'actor_type' => $actorType,
            'action' => Str::limit($action, 255, ''),
            'outcome' => $this->outcomeFor($status),
            'module' => $this->deriveModule($routeName, $routeTemplate),
            'route' => Str::limit($routeName ?: $routeTemplate, 255, ''),
            'method' => $request->method(),
            'response_status' => $status,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 2000, ''),
            'context' => json_encode(
                $this->safeContext($request, $exception),
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE,
            ) ?: '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('court_system_audits')->insert($payload);
        } catch (Throwable $auditFailure) {
            Log::channel('court.audit')->critical('system_audit_database_write_failed', [
                'court.audit' => $payload,
                'failure' => $auditFailure::class,
                'failure_message' => Str::limit($auditFailure->getMessage(), 500, ''),
            ]);
        }
    }

    /** @return array{0: string, 1: int|null} */
    private function resolveActor(Request $request): array
    {
        $actor = $request->attributes->get('audit_actor');
        $actor = $actor instanceof Authenticatable ? $actor : $request->user();

        if ($actor === null) {
            foreach (['court', 'applicant', 'respondent', 'sanctum'] as $guard) {
                try {
                    $actor = Auth::guard($guard)->user();
                } catch (Throwable) {
                    $actor = null;
                }

                if ($actor !== null) {
                    break;
                }
            }
        }

        return match (true) {
            $actor instanceof Applicant => ['applicant', (int) $actor->getAuthIdentifier()],
            $actor instanceof Respondent => ['respondent', (int) $actor->getAuthIdentifier()],
            $actor instanceof User => ['user', (int) $actor->getAuthIdentifier()],
            $actor instanceof Authenticatable => [Str::snake(class_basename($actor)), (int) $actor->getAuthIdentifier()],
            default => ['guest', null],
        };
    }

    private function statusFor(?Throwable $exception): int
    {
        return $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    }

    private function outcomeFor(int $status): string
    {
        if ($status >= 500) {
            return 'error';
        }

        return $status >= 400 ? 'failure' : 'success';
    }

    private function deriveModule(?string $routeName, string $routeTemplate): string
    {
        $source = $routeName ?: str_replace('/', '.', $routeTemplate);

        return Str::limit(explode('.', $source)[0] ?: 'system', 255, '');
    }

    /** @return array<string, mixed> */
    private function safeContext(Request $request, ?Throwable $exception): array
    {
        $context = [
            'path' => $request->path(),
            'route_parameters' => $this->sanitize($request->route()?->parameters() ?? []),
            'query' => $this->sanitize($request->query()),
            'input' => $this->sanitize($request->except(['_token'])),
            'files' => collect($request->allFiles())->map(
                fn (mixed $file): mixed => is_array($file)
                    ? collect($file)->map(fn (mixed $item): array => $this->fileMetadata($item))->all()
                    : $this->fileMetadata($file),
            )->all(),
        ];

        if ($exception !== null) {
            $context['exception'] = $exception::class;
        }

        return $context;
    }

    /** @return array<string, mixed> */
    private function sanitize(array $values): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            $keyName = (string) $key;

            if ($this->isSensitive($keyName)) {
                $safe[$keyName] = '[redacted]';

                continue;
            }

            $safe[$keyName] = match (true) {
                is_array($value) => $this->sanitize($value),
                is_bool($value), is_int($value), is_float($value), $value === null => $value,
                is_object($value) && method_exists($value, 'getKey') => $value->getKey(),
                is_string($value) => Str::limit($value, 500, '[truncated]'),
                default => '['.get_debug_type($value).']',
            };
        }

        return $safe;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = Str::lower($key);

        return collect(self::SENSITIVE_KEY_PARTS)
            ->contains(fn (string $part): bool => str_contains($normalized, $part));
    }

    /** @return array<string, mixed> */
    private function fileMetadata(mixed $file): array
    {
        if (! is_object($file)) {
            return ['type' => get_debug_type($file)];
        }

        return [
            'original_name' => method_exists($file, 'getClientOriginalName')
                ? Str::limit((string) $file->getClientOriginalName(), 255, '')
                : null,
            'mime_type' => method_exists($file, 'getClientMimeType') ? $file->getClientMimeType() : null,
            'size' => method_exists($file, 'getSize') ? $file->getSize() : null,
        ];
    }
}
