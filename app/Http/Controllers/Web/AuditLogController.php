<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $search = $request->string('search')->toString();
        $eventType = $request->string('event_type')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $query = AuditLog::query()
            ->orderByDesc('created_at')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('actor_user_id', ci_like_operator(), "%{$search}%")
                        ->orWhere('auditable_type', ci_like_operator(), "%{$search}%")
                        ->orWhere('auditable_id', ci_like_operator(), "%{$search}%");
                });
            })
            ->when($eventType, fn ($q) => $q->where('event_type', ci_like_operator(), "%{$eventType}%"))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $paginated = $query->paginate(50)->withQueryString();

        // Enrich actor display names where possible
        $actorIds = $paginated->pluck('actor_user_id')->filter()->unique()->values();
        $actors = $actorIds->isNotEmpty()
            ? User::query()->whereIn('id', $actorIds)->pluck('name', 'id')
            : collect();

        $rows = $paginated->getCollection()->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'event_type' => $log->event_type instanceof \BackedEnum ? $log->event_type->value : $log->event_type,
            'actor_user_id' => $log->actor_user_id,
            'actor_name' => $log->actor_user_id ? $actors->get($log->actor_user_id) : null,
            'auditable_type' => $log->auditable_type
                ? class_basename($log->auditable_type)
                : null,
            'auditable_id' => $log->auditable_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
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
            'filters' => $request->only('search', 'event_type', 'from', 'to'),
        ]);
    }
}
