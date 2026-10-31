<?php

declare(strict_types=1);

namespace Modules\Court\Application\Listeners;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class RecordModelMutation
{
    private const REDACTED_FIELD_PARTS = [
        'password', 'token', 'secret', 'otp', 'signature', 'stamp', 'private_key',
        'api_key', 'national_id', 'email', 'phone', 'address', 'date_of_birth',
    ];

    /** @param array<int, mixed> $payload */
    public function handle(string $eventName, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model || in_array($model->getTable(), ['court_audits', 'court_system_audits', 'court_case_audits'], true)) {
            return;
        }

        $event = Str::before(Str::after($eventName, 'eloquent.'), ':');
        [$actorType, $actorId] = $this->resolveActor();
        $request = app()->runningInConsole() ? null : request();
        $changes = $event === 'deleted' ? $model->getAttributes() : $model->getChanges();

        $oldValues = [];
        $newValues = [];

        foreach ($changes as $field => $value) {
            if (in_array($field, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $oldValues[$field] = $this->safeValue($field, $model->getRawOriginal($field));
            $newValues[$field] = $this->safeValue($field, $value);
        }

        $context = [
            'origin' => app()->runningInConsole() ? 'console_or_job' : 'http',
            'model' => $model::class,
            'table' => $model->getTable(),
            'model_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ];

        $record = [
            'request_id' => $request?->attributes->get('request_id'),
            'user_id' => $actorId,
            'actor_type' => $actorType,
            'action' => "model.{$event}",
            'outcome' => 'success',
            'module' => Str::snake(class_basename($model)),
            'route' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'response_status' => null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 2000, '') : null,
            'context' => json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) ?: '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('court_system_audits')->insert($record);
        } catch (Throwable $auditFailure) {
            Log::channel('court.audit')->critical('model_mutation_audit_database_write_failed', [
                'court.audit' => $record,
                'failure' => $auditFailure::class,
            ]);
        }
    }

    /** @return array{0: string, 1: int|null} */
    private function resolveActor(): array
    {
        foreach (['court', 'applicant', 'respondent', 'sanctum'] as $guard) {
            try {
                $actor = Auth::guard($guard)->user();
            } catch (Throwable) {
                $actor = null;
            }

            if ($actor instanceof Authenticatable) {
                return match (true) {
                    $actor instanceof Applicant => ['applicant', (int) $actor->getAuthIdentifier()],
                    $actor instanceof Respondent => ['respondent', (int) $actor->getAuthIdentifier()],
                    $actor instanceof User => ['user', (int) $actor->getAuthIdentifier()],
                    default => [Str::snake(class_basename($actor)), (int) $actor->getAuthIdentifier()],
                };
            }
        }

        return [app()->runningInConsole() ? 'system' : 'guest', null];
    }

    private function safeValue(string $field, mixed $value): mixed
    {
        $normalized = Str::lower($field);

        if (collect(self::REDACTED_FIELD_PARTS)->contains(
            fn (string $part): bool => str_contains($normalized, $part),
        )) {
            return '[redacted]';
        }

        return match (true) {
            is_bool($value), is_int($value), is_float($value), $value === null => $value,
            is_string($value) => Str::limit($value, 500, '[truncated]'),
            default => '['.get_debug_type($value).']',
        };
    }
}
