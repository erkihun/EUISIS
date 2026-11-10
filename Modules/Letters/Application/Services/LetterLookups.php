<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Letters\Domain\Enums\TemplateStatus;
use Modules\Letters\Domain\Models\LetterRegistryOffice;
use Modules\Letters\Domain\Models\LetterTemplate;

/** Pick-lists for the forms, always narrowed to what the user may act for. */
class LetterLookups
{
    public function __construct(private readonly LettersAccess $access) {}

    /**
     * Institutions the user may act for with $permission (the owner pick-list).
     *
     * @return list<array{id: string, code: string, name_en: string, name_am: string|null}>
     */
    public function actingOrganizations(User $user, string $permission): array
    {
        if (! $user->can($permission)) {
            return [];
        }
        $query = Organization::query()->where('status', 'active')->orderBy('name_en');
        if (! $this->access->isCitywide($user)) {
            $query->whereIn('id', $this->access->organizationIds($user));
        }

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map(fn (Organization $o): array => ['id' => $o->id, 'code' => $o->code, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->all();
    }

    /**
     * Every active institution, for the sender/recipient pick-list.
     *
     * @return list<array{id: string, code: string, name_en: string, name_am: string|null}>
     */
    public function allOrganizations(): array
    {
        return Organization::query()->where('status', 'active')->orderBy('name_en')->limit(1000)->get(['id', 'code', 'name_en', 'name_am'])
            ->map(fn (Organization $o): array => ['id' => $o->id, 'code' => $o->code, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->all();
    }

    /** @return list<array{id: string, code: string|null, name_en: string, name_am: string|null}> */
    public function units(string $organizationId): array
    {
        return OrganizationUnit::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name_en')->limit(500)
            ->get(['id', 'code', 'name_en', 'name_am'])->map(fn (OrganizationUnit $u): array => ['id' => $u->id, 'code' => $u->code, 'name_en' => $u->name_en, 'name_am' => $u->name_am])->all();
    }

    /**
     * The registry office (መዝገብ ቤት) a forwarding starts from: the active one
     * the actor works in, else the first active one the institution designated.
     */
    public function registryOffice(string $organizationId, ?User $actor = null): ?LetterRegistryOffice
    {
        $offices = LetterRegistryOffice::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('created_at')->get();
        $unit = $actor !== null ? $this->unitOf($actor, $organizationId) : null;

        return ($unit !== null ? $offices->firstWhere('organization_unit_id', $unit) : null) ?? $offices->first();
    }

    /** The unit the user currently works in, when it belongs to this institution. */
    public function unitOf(User $user, string $organizationId): ?string
    {
        $assignment = $user->employee?->currentAssignment;

        return $assignment !== null && $assignment->organization_id === $organizationId ? $assignment->organization_unit_id : null;
    }

    /**
     * Active accounts whose employee currently works in the unit, read from the structure.
     *
     * @return Collection<int, User>
     */
    public function staffOf(string $unitId): Collection
    {
        return User::query()->where('status', 'active')
            ->whereHas('employee.currentAssignment', fn ($a) => $a->where('organization_unit_id', $unitId))
            ->with('employee.currentAssignment.position:id,title_en,title_am')
            ->orderBy('name')->limit(200)->get()->toBase();
    }

    /**
     * People a letter can be routed to or signed by in an institution: active
     * accounts whose employee currently works there or whose scope names it.
     *
     * @return list<array{id: int, name: string}>
     */
    public function people(string $organizationId): array
    {
        return User::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereHas('employee.currentAssignment', fn ($a) => $a->where('organization_id', $organizationId))
                ->orWhereHas('organizationScopes', fn ($s) => $s->where('is_active', true)->where('organization_id', $organizationId)))
            ->orderBy('name')->limit(300)->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all();
    }

    /**
     * Published templates offered to an institution, with their current version.
     *
     * @return list<array<string, mixed>>
     */
    public function templatesFor(string $organizationId): array
    {
        return LetterTemplate::query()->where('status', TemplateStatus::Published->value)
            ->where(fn ($q) => $q->whereNull('owner_organization_id')->orWhere('owner_organization_id', $organizationId))
            ->with('versions')->orderBy('title_en')->get()
            ->map(function (LetterTemplate $t): ?array {
                $version = $t->versions->firstWhere('version_no', $t->current_version_no);
                if ($version === null) {
                    return null;
                }

                return [
                    'id' => $t->id, 'version_id' => $version->id, 'code' => $t->code, 'title_en' => $t->title_en, 'title_am' => $t->title_am,
                    'category' => $t->category->value, 'language' => $t->language, 'platform' => $t->isPlatform(),
                    'subject' => $version->subject, 'body' => $version->body, 'placeholders' => $version->placeholderList(),
                ];
            })->filter()->values()->all();
    }
}
