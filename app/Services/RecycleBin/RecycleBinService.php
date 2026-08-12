<?php

declare(strict_types=1);

namespace App\Services\RecycleBin;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CodeRule;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RecycleBinService
{
    public function __construct(
        private readonly RecycleBinRegistry $registry,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function records(Request $request): LengthAwarePaginator
    {
        $records = collect();
        $types = $request->string('type')->toString() !== ''
            ? [$request->string('type')->toString()]
            : array_keys($this->registry->supportedTypes());

        foreach ($types as $type) {
            $definition = $this->registry->definition($type);

            if (! $request->user()?->can($definition['view_deleted_permission'])) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $definition['model'];

            /** @var Builder $query */
            $query = $modelClass::query()->onlyTrashed();
            $this->applyFilters($query, $request);

            $query->latest('deleted_at')
                ->limit(250)
                ->get()
                ->each(fn (Model $record) => $records->push([
                    'type' => $type,
                    'definition' => $definition,
                    'record' => $record,
                ]));
        }

        $sorted = $records->sortByDesc(fn (array $row): int => (int) $row['record']->deleted_at?->getTimestamp())->values();

        return $this->paginateCollection($sorted, max(1, (int) $request->integer('page', 1)), 25);
    }

    public function findDeleted(string $type, string $id): Model
    {
        $definition = $this->registry->definition($type);

        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];

        return $modelClass::query()->onlyTrashed()->findOrFail($id);
    }

    public function restore(string $type, string $id, User $actor, Request $request): Model
    {
        $record = $this->findDeleted($type, $id);
        $definition = $this->registry->definition($type);

        abort_unless($actor->can($definition['restore_permission']) && $actor->can('restore', $record), 403);

        $oldValues = $record->toArray();
        $this->guardRestoreConflicts($record);

        $record->restore();
        $record->forceFill([
            ...$this->restoreState($record),
            'deleted_by' => null,
            'deletion_reason' => null,
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordRestored,
            $actor,
            $record->fresh(),
            $this->organizationId($record),
            oldValues: $oldValues,
            newValues: $record->fresh()->toArray(),
            request: $request,
        );

        return $record->fresh() ?? $record;
    }

    public function forceDelete(string $type, string $id, User $actor, Request $request): void
    {
        $record = $this->findDeleted($type, $id);
        $definition = $this->registry->definition($type);

        abort_unless($actor->can($definition['restore_permission']), 403);

        $oldValues = $record->toArray();
        $organizationId = $this->organizationId($record);

        try {
            $record->forceDelete();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'record' => __('recycle-bin.force_delete_conflict'),
                ]);
            }
            throw $e;
        }

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $record,
            $organizationId,
            oldValues: $oldValues,
            newValues: [],
            request: $request,
        );
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->string('search')->toString() !== '', function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function (Builder $nested) use ($search): void {
                    foreach (['code', 'job_position_code', 'name', 'name_en', 'name_am', 'title_en', 'title_am'] as $column) {
                        if ($this->hasColumn($nested, $column)) {
                            $nested->orWhere($column, ci_like_operator(), "%{$search}%");
                        }
                    }
                });
            })
            ->when($request->date('deleted_from'), fn (Builder $query, mixed $date) => $query->whereDate('deleted_at', '>=', $date))
            ->when($request->date('deleted_to'), fn (Builder $query, mixed $date) => $query->whereDate('deleted_at', '<=', $date))
            ->when($request->integer('deleted_by') > 0, fn (Builder $query) => $query->where('deleted_by', $request->integer('deleted_by')));
    }

    private function hasColumn(Builder $query, string $column): bool
    {
        return in_array($column, $query->getModel()->getConnection()->getSchemaBuilder()->getColumnListing($query->getModel()->getTable()), true);
    }

    private function organizationId(Model $record): ?string
    {
        return is_string($record->getAttribute('organization_id')) ? $record->getAttribute('organization_id') : null;
    }

    private function guardRestoreConflicts(Model $record): void
    {
        if (! $record instanceof CodeRule) {
            return;
        }

        $exists = CodeRule::query()
            ->whereKeyNot($record->getKey())
            ->where('entity_type', $record->entity_type)
            ->where('scope_type', $record->scope_type)
            ->where('scope_id', $record->scope_id)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'record' => __('recycle-bin.restore_conflict'),
            ]);
        }
    }

    private function restoreState(Model $record): array
    {
        $attributes = $record->getAttributes();
        $state = [];

        if (array_key_exists('is_active', $attributes)) {
            $state['is_active'] = true;
        }

        if (array_key_exists('status', $attributes)) {
            $state['status'] = 'active';
        }

        if ($record instanceof CodeRule) {
            $state['active_scope_key'] = CodeRule::buildActiveScopeKey($record->entity_type, $record->scope_type, $record->scope_id);
        }

        return $state;
    }

    private function paginateCollection(Collection $records, int $page, int $perPage): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(
            $records->forPage($page, $perPage)->values(),
            $records->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }
}
