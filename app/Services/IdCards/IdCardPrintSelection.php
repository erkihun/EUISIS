<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Actions\IdCards\CreatePrintBatchAction;
use App\Enums\CardStatus;
use App\Models\IdCard;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cards a bulk print run can pick from: current, printable cards inside
 * the actor's organization scope and not already waiting in an open batch,
 * narrowed by organization, organizational unit (optionally with its
 * sub-units), what the card needs, and a search.
 *
 * One definition serves the listing and "batch everything matching", so the
 * count an operator sees is the count that gets batched.
 */
final readonly class IdCardPrintSelection
{
    /** What the operator is printing for. */
    public const NEEDS = ['pending_print', 'reprint_required', 'all'];

    public function __construct(private OrganizationScopeService $scope) {}

    /**
     * @param  array{organization_id?: ?string, organization_unit_id?: ?string, include_sub_units?: bool|string|null, need?: ?string, search?: ?string}  $filters
     * @return Builder<IdCard>
     */
    public function query(User $actor, array $filters): Builder
    {
        $need = in_array($filters['need'] ?? null, self::NEEDS, true) ? $filters['need'] : 'pending_print';
        $unitIds = $this->unitIds($filters['organization_unit_id'] ?? null, filter_var($filters['include_sub_units'] ?? true, FILTER_VALIDATE_BOOL));

        return IdCard::query()
            ->where('is_current', true)
            ->whereIn('status', array_map(fn (CardStatus $status) => $status->value, CreatePrintBatchAction::PRINTABLE))
            // A scoped actor only ever sees their own organizations; an empty
            // scope means no cards, never all of them.
            ->when(! $this->scope->isUnrestricted($actor), fn ($query) => $query->whereHas(
                'employee.currentAssignment',
                fn ($assignment) => $assignment->whereIn('organization_id', $this->scope->accessibleOrganizationIds($actor)),
            ))
            ->whereDoesntHave('printBatchItems', fn ($item) => $item
                ->where('status', 'pending')
                ->whereHas('batch', fn ($batch) => $batch->whereIn('status', ['draft', 'printing'])))
            ->when($need === 'pending_print', fn ($query) => $query->where('status', CardStatus::PendingPrint->value))
            ->when($need === 'reprint_required', fn ($query) => $query->where('reprint_required', true))
            ->when($filters['organization_id'] ?? null, fn ($query, string $organizationId) => $query->whereHas(
                'employee.currentAssignment',
                fn ($assignment) => $assignment->where('organization_id', $organizationId),
            ))
            ->when($unitIds !== null, fn ($query) => $query->whereHas(
                'employee.currentAssignment',
                fn ($assignment) => $assignment->whereIn('organization_unit_id', $unitIds),
            ))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(fn ($nested) => $nested
                    ->whereLike('card_number', "%{$search}%", caseSensitive: false)
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->whereLike('employee_number', "%{$search}%", caseSensitive: false)
                        ->orWhereLike('full_name', "%{$search}%", caseSensitive: false)
                        ->orWhereLike('first_name', "%{$search}%", caseSensitive: false)
                        ->orWhereLike('last_name', "%{$search}%", caseSensitive: false)));
            });
    }

    /**
     * The unit and, when asked, every unit beneath it. Null means no unit filter.
     *
     * @return array<int, string>|null
     */
    private function unitIds(?string $unitId, bool $withChildren): ?array
    {
        if ($unitId === null || $unitId === '') {
            return null;
        }
        $ids = [$unitId];
        if (! $withChildren) {
            return $ids;
        }
        // Walk down level by level; units form a tree, and the seen-list
        // stops a corrupt cycle from looping.
        $frontier = [$unitId];
        while ($frontier !== []) {
            $frontier = OrganizationUnit::query()
                ->whereIn('parent_unit_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
