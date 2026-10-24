<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /** Without an explicit range the viewer shows this many recent days. */
    private const DEFAULT_WINDOW_DAYS = 30;

    /**
     * Employee personal-data keys that audit values may carry (e.g. an
     * employee `toArray()` snapshot). Shown only to holders of
     * employees.viewPii; credentials are already redacted at write time.
     */
    private const PII_KEYS = [
        'phone', 'email', 'address', 'date_of_birth', 'personal_email',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
        'emergency_contact_address', 'photo_path', 'photo_url',
    ];

    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        /** @var User $actor */
        $actor = $request->user();

        $search = trim($request->string('search')->toString());
        $eventType = trim($request->string('event_type')->toString());
        // Correlation id of one request, and who acted: a signed-in person or an application/system job.
        $requestId = trim($request->string('request_id')->toString());
        $principal = $request->string('principal')->toString();
        $from = $this->date($request->string('from')->toString());
        $to = $this->date($request->string('to')->toString());
        if ($from === null && $to === null) {
            $from = now()->subDays(self::DEFAULT_WINDOW_DAYS)->startOfDay();
        }

        $query = AuditLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    // actor_user_id is a bigint: compare numerically, never as text.
                    if (ctype_digit($search)) {
                        $q->where('actor_user_id', (int) $search);
                    }
                    $q->orWhere('auditable_id', $search)
                        ->orWhere('auditable_type', ci_like_operator(), "%{$search}%");
                });
            })
            ->when($eventType !== '', fn ($q) => $q->where('event_type', ci_like_operator(), "{$eventType}%"))
            ->when(Str::isUuid($requestId), fn ($q) => $q->where('request_id', $requestId))
            ->when($principal === 'person', fn ($q) => $q->whereNotNull('actor_user_id'))
            ->when($principal === 'system', fn ($q) => $q->whereNull('actor_user_id'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->copy()->endOfDay()));

        // A scoped auditor sees only their organizations' events; system-wide
        // rows (no organization) stay with unrestricted auditors.
        $scope->applyOrganizationScope($query, $actor);

        $paginated = $query->paginate(50)->withQueryString();

        $actorIds = $paginated->pluck('actor_user_id')->filter()->unique()->values();
        $actors = $actorIds->isNotEmpty()
            ? User::query()->whereIn('id', $actorIds)->pluck('name', 'id')
            : collect();

        $showPii = $actor->can('employees.viewPii');

        $rows = $paginated->getCollection()->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'event_type' => $log->event_type instanceof \BackedEnum ? $log->event_type->value : $log->event_type,
            'actor_user_id' => $log->actor_user_id,
            'actor_name' => $log->actor_user_id ? $actors->get($log->actor_user_id) : null,
            'auditable_type' => $log->auditable_type
                ? class_basename($log->auditable_type)
                : null,
            'auditable_id' => $log->auditable_id,
            'old_values' => $showPii ? $log->old_values : $this->maskPii($log->old_values),
            'new_values' => $showPii ? $log->new_values : $this->maskPii($log->new_values),
            'created_at' => $log->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('AuditLogs/Index', [
            'auditLogs' => $rows,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
            ],
            'filters' => [
                ...$request->only('search', 'event_type', 'request_id', 'principal'),
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }

    private function date(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function maskPii(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->maskPii($value);
            } elseif (in_array(strtolower((string) $key), self::PII_KEYS, true) && $value !== null && $value !== '') {
                $values[$key] = '[MASKED]';
            }
        }

        return $values;
    }
}
