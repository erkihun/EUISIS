<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Models\InstitutionMandate;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Powers and the function tree under a mandate (brief §6–8). A function's
 * `path` is its ancestor chain, so a subtree is one query and a cycle is a
 * string prefix test; moving a branch rewrites the paths beneath it.
 */
class FunctionService
{
    public const POWER_FIELDS = ['code', 'title_en', 'title_am', 'description_en', 'description_am', 'legal_reference', 'sequence', 'effective_from', 'effective_to', 'status'];

    public const FUNCTION_FIELDS = ['code', 'title_en', 'title_am', 'description_en', 'description_am', 'legal_reference', 'sequence', 'effective_from', 'effective_to', 'status', 'power_id'];

    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    /** @param array<string, mixed> $data */
    public function createPower(User $actor, InstitutionMandate $mandate, array $data): OrganizationPower
    {
        return DB::transaction(function () use ($actor, $mandate, $data): OrganizationPower {
            $power = new OrganizationPower([
                ...$this->only($data, self::POWER_FIELDS),
                'organization_id' => $mandate->organization_id,
                'mandate_id' => $mandate->getKey(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $power->save();
            $this->audit->record(AuditEventType::PowerSaved, $actor, $power, $power->only(self::POWER_FIELDS));

            return $power;
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePower(User $actor, OrganizationPower $power, array $data): OrganizationPower
    {
        return DB::transaction(function () use ($actor, $power, $data): OrganizationPower {
            $old = $power->only(self::POWER_FIELDS);
            $power->fill([...$this->only($data, self::POWER_FIELDS), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::PowerSaved, $actor, $power, $power->only(self::POWER_FIELDS), $old);

            return $power;
        });
    }

    public function removePower(User $actor, OrganizationPower $power): void
    {
        DB::transaction(function () use ($actor, $power): void {
            OrganizationFunction::query()->where('power_id', $power->getKey())->update(['power_id' => null]);
            $power->delete();
            $this->audit->record(AuditEventType::PowerRemoved, $actor, $power);
        });
    }

    /** @param array<string, mixed> $data */
    public function createFunction(User $actor, InstitutionMandate $mandate, array $data): OrganizationFunction
    {
        return DB::transaction(function () use ($actor, $mandate, $data): OrganizationFunction {
            $parent = $this->parentFor($mandate->organization_id, $data['parent_function_id'] ?? null);
            $this->assertPowerBelongs($mandate, $data['power_id'] ?? null);

            $function = new OrganizationFunction([
                ...$this->only($data, self::FUNCTION_FIELDS),
                'organization_id' => $mandate->organization_id,
                'mandate_id' => $mandate->getKey(),
                'parent_function_id' => $parent?->getKey(),
                'depth' => $parent ? $parent->depth + 1 : 0,
                'path' => '',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $function->save();
            $function->forceFill(['path' => $parent ? $parent->path.'/'.$function->getKey() : $function->getKey()])->save();

            $this->audit->record(AuditEventType::FunctionSaved, $actor, $function, $function->only([...self::FUNCTION_FIELDS, 'parent_function_id']));

            return $function;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateFunction(User $actor, OrganizationFunction $function, array $data): OrganizationFunction
    {
        return DB::transaction(function () use ($actor, $function, $data): OrganizationFunction {
            $this->assertPowerBelongs($function->mandate, $data['power_id'] ?? $function->power_id);
            $old = $function->only(self::FUNCTION_FIELDS);
            $function->fill([...$this->only($data, self::FUNCTION_FIELDS), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::FunctionSaved, $actor, $function, $function->only(self::FUNCTION_FIELDS), $old);

            return $function;
        });
    }

    /** Re-parent a function; its whole branch follows. */
    public function moveFunction(User $actor, OrganizationFunction $function, ?string $parentId): OrganizationFunction
    {
        return DB::transaction(function () use ($actor, $function, $parentId): OrganizationFunction {
            $parent = $this->parentFor($function->organization_id, $parentId);
            if ($parent !== null && $function->isAncestorOf($parent)) {
                throw ValidationException::withMessages(['parent_function_id' => __('organization-design.function_cycle')]);
            }

            $oldPath = $function->path;
            $oldDepth = $function->depth;
            $newPath = $parent ? $parent->path.'/'.$function->getKey() : (string) $function->getKey();
            $newDepth = $parent ? $parent->depth + 1 : 0;

            // Lock the branch, then rewrite every path beneath it in one statement each.
            $branch = OrganizationFunction::query()->subtreeOf($function)->lockForUpdate()->get(['id', 'path', 'depth']);
            foreach ($branch as $node) {
                OrganizationFunction::query()->whereKey($node->getKey())->update([
                    'path' => $newPath.substr($node->path, strlen($oldPath)),
                    'depth' => $node->depth - $oldDepth + $newDepth,
                ]);
            }
            $function->forceFill(['parent_function_id' => $parent?->getKey(), 'updated_by' => $actor->getKey()])->save();
            $function->refresh();

            $this->audit->record(AuditEventType::FunctionMoved, $actor, $function, ['parent_function_id' => $parent?->getKey()], ['parent_function_id' => $oldPath === $newPath ? null : $function->getOriginal('parent_function_id')]);

            return $function;
        });
    }

    public function removeFunction(User $actor, OrganizationFunction $function): void
    {
        DB::transaction(function () use ($actor, $function): void {
            if ($function->children()->exists()) {
                throw ValidationException::withMessages(['function' => __('organization-design.function_has_children')]);
            }
            $function->delete();
            $this->audit->record(AuditEventType::FunctionRemoved, $actor, $function);
        });
    }

    /**
     * The function tree of an organization, nested, from one query.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(string $organizationId, ?string $mandateId = null): array
    {
        $rows = OrganizationFunction::query()
            ->where('organization_id', $organizationId)
            ->when($mandateId, fn ($q) => $q->where('mandate_id', $mandateId))
            ->with('power:id,code,title_en,title_am')
            ->orderBy('depth')->orderBy('sequence')->orderBy('code')
            ->get();

        return $this->nest($rows);
    }

    /**
     * @param  Collection<int, OrganizationFunction>  $rows
     * @return list<array<string, mixed>>
     */
    private function nest(Collection $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getKey()] = [
                'id' => $row->getKey(), 'code' => $row->code, 'title_en' => $row->title_en, 'title_am' => $row->title_am,
                'description_en' => $row->description_en, 'description_am' => $row->description_am,
                'legal_reference' => $row->legal_reference, 'sequence' => $row->sequence, 'status' => $row->status,
                'depth' => $row->depth, 'mandate_id' => $row->mandate_id, 'parent_function_id' => $row->parent_function_id,
                'power' => $row->power ? ['id' => $row->power->id, 'code' => $row->power->code, 'title_en' => $row->power->title_en, 'title_am' => $row->power->title_am] : null,
                'effective_from' => $row->effective_from?->toDateString(), 'effective_to' => $row->effective_to?->toDateString(),
                'children' => [],
            ];
        }
        $roots = [];
        foreach ($rows as $row) {
            $parent = $row->parent_function_id;
            if ($parent !== null && isset($byId[$parent])) {
                $byId[$parent]['children'][] = &$byId[$row->getKey()];
            } else {
                $roots[] = &$byId[$row->getKey()];
            }
        }

        return $roots;
    }

    private function parentFor(string $organizationId, ?string $parentId): ?OrganizationFunction
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        $parent = OrganizationFunction::query()->whereKey($parentId)->where('organization_id', $organizationId)->first();
        if ($parent === null) {
            throw ValidationException::withMessages(['parent_function_id' => __('organization-design.function_parent_invalid')]);
        }

        return $parent;
    }

    private function assertPowerBelongs(InstitutionMandate $mandate, ?string $powerId): void
    {
        if ($powerId === null || $powerId === '') {
            return;
        }
        if (! OrganizationPower::query()->whereKey($powerId)->where('mandate_id', $mandate->getKey())->exists()) {
            throw ValidationException::withMessages(['power_id' => __('organization-design.power_invalid')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function only(array $data, array $fields): array
    {
        return array_intersect_key($data, array_flip($fields));
    }
}
