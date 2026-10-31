<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'source' => ['nullable', 'in:system,case,legacy_model'],
            'outcome' => ['nullable', 'in:success,failure,error'],
            'actor_type' => ['nullable', 'in:user,applicant,respondent,guest,system'],
            'method' => ['nullable', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = DB::query()->fromSub($this->unifiedAuditQuery(), 'audit_events');
        $this->applyFilters($query, $filters);

        $events = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $pageRows = collect($events->items());
        $actors = $this->resolveActorNames($pageRows);
        $caseNumbers = $this->resolveCaseNumbers($pageRows);
        $modelCompanions = $this->modelCompanions($pageRows);

        $events->through(function (object $event) use ($actors, $caseNumbers, $modelCompanions): object {
            $event->actor_type = $this->normalizeActorType($event->actor_type);
            $event->actor_name = $this->actorName($event, $actors);
            $event->source_label = match ($event->source) {
                'case' => 'Case activity',
                'legacy_model' => 'Model history',
                default => 'System activity',
            };
            $event->details = $this->decodeDetails($event);
            $companion = $event->request_id ? ($modelCompanions[$event->request_id] ?? null) : null;
            $descriptiveDetails = $companion ?: $event->details;
            $event->target_label = $event->source === 'case'
                ? ($caseNumbers[(int) $event->target_id] ?? "Case #{$event->target_id}")
                : $this->targetLabel($event, $descriptiveDetails);
            $event->action_label = $this->actionLabel($event, $descriptiveDetails);
            $event->description = $this->description($event);

            return $event;
        });

        // The page (React, Court/Audit/Index): the decorated events as plain arrays.
        return Inertia::render('Court/Audit/Index', [
            'events' => $events->through(fn (object $event): array => [
                'id' => (int) $event->id,
                'source' => (string) $event->source,
                'source_label' => (string) $event->source_label,
                'occurred_at' => $event->occurred_at ? \Illuminate\Support\Carbon::parse($event->occurred_at)->toIso8601String() : null,
                'action' => $event->action,
                'action_label' => (string) $event->action_label,
                'description' => $event->description,
                'actor_type' => (string) $event->actor_type,
                'actor_name' => $event->actor_name,
                'target_label' => $event->target_label,
                'outcome' => $event->outcome ?? null,
                'method' => $event->method ?? null,
                'route' => $event->route ?? null,
                'ip' => $event->ip ?? null,
                'request_id' => $event->request_id ?? null,
                'response_status' => isset($event->response_status) ? (int) $event->response_status : null,
                'details' => (object) ($event->details ?? []),
            ]),
            'filters' => $filters,
            'options' => [
                'sources' => ['system', 'case', 'legacy_model'],
                'outcomes' => ['success', 'failure', 'error'],
                'actorTypes' => ['user', 'applicant', 'respondent', 'guest', 'system'],
                'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            ],
        ]);
    }

    private function unifiedAuditQuery(): Builder
    {
        $system = DB::table('court_system_audits')->select([
            'id',
            DB::raw("'system' as source"),
            'created_at as occurred_at',
            'action',
            'actor_type',
            'user_id as actor_id',
            'outcome',
            'response_status',
            'method',
            'route',
            'request_id',
            'ip',
            'module as target_type',
            DB::raw('NULL as target_id'),
            'context',
            DB::raw('NULL as old_values'),
            DB::raw('NULL as new_values'),
        ]);

        $case = DB::table('court_case_audits')->select([
            'id',
            DB::raw("'case' as source"),
            'created_at as occurred_at',
            'action',
            'actor_type',
            'actor_id',
            DB::raw("'success' as outcome"),
            DB::raw('NULL as response_status'),
            DB::raw('NULL as method'),
            DB::raw('NULL as route'),
            DB::raw('NULL as request_id'),
            DB::raw('NULL as ip'),
            DB::raw("'court_case' as target_type"),
            'case_id as target_id',
            'meta as context',
            DB::raw('NULL as old_values'),
            DB::raw('NULL as new_values'),
        ]);

        $legacy = DB::table('court_audits')->select([
            'id',
            DB::raw("'legacy_model' as source"),
            'created_at as occurred_at',
            'event as action',
            'user_type as actor_type',
            'user_id as actor_id',
            DB::raw("'success' as outcome"),
            DB::raw('NULL as response_status'),
            DB::raw('NULL as method'),
            'url as route',
            DB::raw('NULL as request_id'),
            'ip_address as ip',
            'auditable_type as target_type',
            'auditable_id as target_id',
            'tags as context',
            'old_values',
            'new_values',
        ]);

        return $system->unionAll($case)->unionAll($legacy);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $builder) use ($search): void {
                $like = "%{$search}%";
                $builder->where('action', 'like', $like)
                    ->orWhere('route', 'like', $like)
                    ->orWhere('target_type', 'like', $like)
                    ->orWhere('context', 'like', $like)
                    ->orWhere('request_id', 'like', $like);
            });
        }

        foreach (['source', 'outcome', 'method'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['actor_type'])) {
            $actorType = $filters['actor_type'];
            $query->where(function (Builder $builder) use ($actorType): void {
                $builder->where('actor_type', $actorType);

                if ($actorType === 'user') {
                    $builder->orWhere('actor_type', 'web')
                        ->orWhere('actor_type', 'like', '%\\User');
                } elseif (in_array($actorType, ['applicant', 'respondent'], true)) {
                    $builder->orWhere('actor_type', 'like', '%\\'.Str::studly($actorType));
                }
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('occurred_at', '>=', $filters['date_from'].' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $query->where('occurred_at', '<=', $filters['date_to'].' 23:59:59');
        }
    }

    /** @return array<string, Collection<int|string, string>> */
    private function resolveActorNames(Collection $events): array
    {
        $ids = ['user' => collect(), 'applicant' => collect(), 'respondent' => collect()];

        foreach ($events as $event) {
            $type = $this->normalizeActorType($event->actor_type);
            if (isset($ids[$type]) && $event->actor_id !== null) {
                $ids[$type]->push((int) $event->actor_id);
            }
        }

        return [
            'user' => $ids['user']->isEmpty()
                ? collect()
                : DB::table('court_staff')->whereIn('id', $ids['user']->unique())->pluck('name', 'id'),
            'applicant' => $this->personNames('applicants', $ids['applicant']),
            'respondent' => $this->personNames('respondents', $ids['respondent']),
        ];
    }

    private function personNames(string $table, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table($table)
            ->whereIn('id', $ids->unique())
            ->get(['id', 'first_name', 'middle_name', 'last_name'])
            ->mapWithKeys(fn (object $actor): array => [
                $actor->id => trim("{$actor->first_name} {$actor->middle_name} {$actor->last_name}"),
            ]);
    }

    private function resolveCaseNumbers(Collection $events): Collection
    {
        $ids = $events->where('source', 'case')->pluck('target_id')->filter()->unique();

        return $ids->isEmpty()
            ? collect()
            : DB::table('court_cases')->whereIn('id', $ids)->pluck('case_number', 'id');
    }

    private function normalizeActorType(?string $type): string
    {
        $type = Str::lower((string) $type);

        return match (true) {
            str_contains($type, 'applicant') => 'applicant',
            str_contains($type, 'respondent') => 'respondent',
            $type === 'user', $type === 'web', str_ends_with($type, '\\user') => 'user',
            $type === 'system' => 'system',
            default => 'guest',
        };
    }

    /** @param array<string, Collection<int|string, string>> $actors */
    private function actorName(object $event, array $actors): string
    {
        if ($event->actor_id !== null && isset($actors[$event->actor_type])) {
            return $actors[$event->actor_type][(int) $event->actor_id]
                ?? Str::headline($event->actor_type)." #{$event->actor_id}";
        }

        return Str::headline($event->actor_type);
    }

    /** @param array<string, mixed> $details */
    private function targetLabel(object $event, array $details = []): string
    {
        $type = $this->resourceName($event, $details);
        $id = $details['model_id'] ?? $event->target_id ?? $this->routeParameterId($details);
        $name = $this->recordName($details);

        return trim($type
            .($name ? ' "'.$name.'"' : '')
            .($id !== null ? " #{$id}" : ''));
    }

    /** @return array<string, array<string, mixed>> */
    private function modelCompanions(Collection $events): array
    {
        $companions = [];

        foreach ($events as $event) {
            if ($event->source !== 'system' || ! str_starts_with((string) $event->action, 'model.') || ! $event->request_id) {
                continue;
            }

            $companions[$event->request_id] = $this->decodeDetails($event);
        }

        return $companions;
    }

    /** @param array<string, mixed> $details */
    private function actionLabel(object $event, array $details): string
    {
        $action = Str::lower((string) $event->action);
        $verb = match (true) {
            str_contains($action, 'destroy'), str_contains($action, 'deleted'), str_ends_with($action, '.delete') => 'Deleted',
            str_contains($action, 'restore') => 'Restored',
            str_contains($action, 'approve') => 'Approved',
            str_contains($action, 'reject') => 'Rejected',
            str_contains($action, 'download'), str_contains($action, 'export') => 'Downloaded',
            str_contains($action, 'login') && ! str_contains($action, 'failed') => 'Signed in to',
            str_contains($action, 'logout') => 'Signed out of',
            str_contains($action, 'failed') => 'Failed to access',
            str_contains($action, 'store'), str_contains($action, 'created') => 'Created',
            str_contains($action, 'update'), str_contains($action, 'updated'), str_contains($action, 'edit') => 'Updated',
            $event->method === 'GET' => 'Viewed',
            default => Str::headline((string) $event->action),
        };

        return "{$verb} ".$this->resourceName($event, $details);
    }

    private function description(object $event): string
    {
        return "{$event->actor_name}: {$event->action_label} - {$event->target_label}.";
    }

    /** @param array<string, mixed> $details */
    private function resourceName(object $event, array $details): string
    {
        if (! empty($details['model'])) {
            return Str::headline(class_basename($details['model']));
        }

        if ($event->source === 'case') {
            return 'Case';
        }

        if ($event->target_type && $event->target_type !== 'system') {
            return Str::headline(Str::singular(class_basename($event->target_type)));
        }

        $routeBase = Str::before((string) $event->route, '.');

        return $routeBase !== '' ? Str::headline(Str::singular($routeBase)) : 'System';
    }

    /** @param array<string, mixed> $details */
    private function recordName(array $details): ?string
    {
        $values = $details['old_values'] ?? $details['new_values'] ?? $details;

        if (! is_array($values)) {
            return null;
        }

        foreach (['title', 'name', 'case_number', 'subject', 'reference'] as $field) {
            if (isset($values[$field]) && is_scalar($values[$field])) {
                return Str::limit(trim((string) $values[$field]), 100, '...');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $details */
    private function routeParameterId(array $details): int|string|null
    {
        $parameters = $details['route_parameters'] ?? [];

        if (! is_array($parameters)) {
            return null;
        }

        foreach ($parameters as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function decodeDetails(object $event): array
    {
        $details = $this->decodeJson($event->context);

        if ($event->source === 'legacy_model') {
            $details = [
                'old_values' => $this->decodeJson($event->old_values),
                'new_values' => $this->decodeJson($event->new_values),
                'tags' => $event->context,
            ];
        }

        return $details;
    }

    /** @return array<string, mixed> */
    private function decodeJson(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : ['value' => $value];
    }
}
