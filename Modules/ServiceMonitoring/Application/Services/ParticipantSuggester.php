<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Enums\HierarchyVersionStatus;
use App\Enums\InstitutionOfficeLevel;
use App\Models\HierarchyVersion;
use App\Models\InstitutionOffice;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Actions\RegisterParticipant;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/**
 * Proposes participant registry entries from the organization structure
 * (D7). It never guesses silently; every proposal carries its reason:
 *
 *   1. an institution office level of city / sub-city / woreda on the organization
 *   2. otherwise its organization type: SUB_CITY → sub-city, WOREDA → woreda,
 *      a functional or independent type at level order 2 → center
 *   3. a woreda reports through the sub-city that is its parent in the
 *      published hierarchy; without one it is listed but never applied
 *
 * The city administration itself, branches, pools and providers are not proposed.
 */
class ParticipantSuggester
{
    public function __construct(private readonly RegisterParticipant $register) {}

    /**
     * @return list<array{organization_id: string, name_en: ?string, name_am: ?string, level: string, parent_organization_id: ?string, parent_name_en: ?string, applicable: bool, reason: string}>
     */
    public function proposals(): array
    {
        $taken = Participant::query()->whereNull('active_to')->pluck('organization_id')->map(fn ($id): string => (string) $id)->flip();
        $officeLevels = InstitutionOffice::query()->whereNotNull('structural_organization_id')
            ->whereIn('office_level', [InstitutionOfficeLevel::City->value, InstitutionOfficeLevel::SubCity->value, InstitutionOfficeLevel::Woreda->value])
            ->pluck('office_level', 'structural_organization_id');
        $version = HierarchyVersion::query()->where('status', HierarchyVersionStatus::Published->value)->latest('approval_date')->value('id');
        $parents = $version === null ? collect() : OrganizationEdge::query()->where('hierarchy_version_id', $version)->pluck('parent_organization_id', 'child_organization_id');
        $organizations = Organization::query()->with('organizationType:id,code,level_order,category')->where('status', 'active')->orderBy('name_en')->get();
        $names = $organizations->pluck('name_en', 'id');

        $proposals = [];
        foreach ($organizations as $organization) {
            if ($taken->has($organization->id)) {
                continue;
            }
            [$level, $reason] = $this->levelOf($organization, $officeLevels->get($organization->id));
            if ($level === null) {
                continue;
            }
            $parent = $level === ReportingLevel::Woreda ? $parents->get($organization->id) : null;
            $proposals[] = [
                'organization_id' => (string) $organization->id,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'level' => $level->value,
                'parent_organization_id' => $parent !== null ? (string) $parent : null,
                'parent_name_en' => $parent !== null ? $names->get($parent) : null,
                'applicable' => $level !== ReportingLevel::Woreda || $parent !== null,
                'reason' => $reason,
            ];
        }

        return $proposals;
    }

    /**
     * Registers the applicable proposals: centers and sub-cities first, so
     * each woreda can point at its sub-city participant.
     *
     * @param  list<array<string, mixed>>  $proposals
     */
    public function apply(array $proposals, string $activeFrom, ?User $actor): int
    {
        $count = 0;
        foreach ([ReportingLevel::Center, ReportingLevel::SubCity, ReportingLevel::Woreda] as $level) {
            foreach ($proposals as $proposal) {
                if ($proposal['level'] !== $level->value || ! $proposal['applicable']) {
                    continue;
                }
                $parent = null;
                if ($level === ReportingLevel::Woreda) {
                    $parent = Participant::query()->where('organization_id', $proposal['parent_organization_id'])
                        ->where('level', ReportingLevel::SubCity->value)->whereNull('active_to')->value('id');
                    if ($parent === null) {
                        continue;
                    }
                }
                $this->register->execute(['organization_id' => $proposal['organization_id'], 'level' => $level->value, 'parent_participant_id' => $parent, 'active_from' => $activeFrom], $actor);
                $count++;
            }
        }

        return $count;
    }

    /** @return array{0: ?ReportingLevel, 1: string} */
    private function levelOf(Organization $organization, ?string $officeLevel): array
    {
        $byOffice = match ($officeLevel) {
            InstitutionOfficeLevel::City->value => ReportingLevel::Center,
            InstitutionOfficeLevel::SubCity->value => ReportingLevel::SubCity,
            InstitutionOfficeLevel::Woreda->value => ReportingLevel::Woreda,
            default => null,
        };
        if ($byOffice !== null) {
            return [$byOffice, (string) __('service-monitoring.suggest.reasons.office_level', ['level' => $officeLevel])];
        }
        $type = $organization->organizationType;
        $level = match (true) {
            $type === null => null,
            $type->code === 'SUB_CITY' => ReportingLevel::SubCity,
            $type->code === 'WOREDA' => ReportingLevel::Woreda,
            in_array($type->category, ['functional', 'independent'], true) && (int) $type->level_order === 2 => ReportingLevel::Center,
            default => null,
        };

        return [$level, $type === null ? '' : (string) __('service-monitoring.suggest.reasons.type_level', ['type' => $type->code, 'order' => $type->level_order])];
    }
}
