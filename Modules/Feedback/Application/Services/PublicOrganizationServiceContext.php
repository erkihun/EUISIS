<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationUnit;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Modules\Feedback\Contracts\PublicOrganizationServices;

/**
 * The service information an organization feedback form may offer, always
 * scoped to the institution the scanned QR resolved to — never to an
 * organization the browser names.
 *
 * - Services come from the institution's own published service catalog
 *   (`services`, Organization Design). Nothing is invented: an institution
 *   with no published catalog gets a free-text "service received" field, and
 *   the row is stored with service_id NULL and the visitor's words in
 *   service_text (SERVICE_LINKAGE_REQUIRED for that institution).
 * - A small catalog is sent with the page; a large one is searched on the
 *   server (LIST_LIMIT), so the page never carries hundreds of rows.
 * - Relevant work units are the institution's own active, in-force
 *   organization units that carry the "available for public feedback" flag
 *   (`is_public_feedback_selectable`): offices and the units that replaced
 *   its former institution offices are flagged by default, internal
 *   directorates and teams are opt-in. The flag, the status and the
 *   effective window are re-checked on submission; a unit of another
 *   institution is refused whatever the browser sent. "General / Not sure" is
 *   recorded as unit_selection_mode GENERAL with no unit row — never a fake
 *   unit.
 */
final class PublicOrganizationServiceContext implements PublicOrganizationServices
{
    /** Up to this many published services travel with the page; more are searched. */
    public const LIST_LIMIT = 150;

    public const SEARCH_LIMIT = 20;

    public const SEARCH_MIN_LENGTH = 2;

    /** Public-selectable work units sent with the page; the page filters them client-side. */
    public const UNIT_LIST_LIMIT = 200;

    /** Published, in force today, and not deleted. */
    public function servicesQuery(string $organizationId): Builder
    {
        $today = now()->toDateString();

        return Service::query()
            ->where('organization_id', $organizationId)
            ->where('status', OrganizationDesignStatus::Published->value)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));
    }

    /**
     * What the page gets: the list itself, a search box, or nothing (free text).
     *
     * @return array{mode: 'list'|'search'|'none', items: list<array{id: string, code: string, name: string}>}
     */
    public function serviceOptions(string $organizationId, string $locale): array
    {
        $count = $this->servicesQuery($organizationId)->count();

        if ($count === 0) {
            return ['mode' => 'none', 'items' => []];
        }

        if ($count > self::LIST_LIMIT) {
            return ['mode' => 'search', 'items' => []];
        }

        return ['mode' => 'list', 'items' => $this->present($this->servicesQuery($organizationId)->orderBy('name_en')->get(), $locale)];
    }

    /** @return list<array{id: string, code: string, name: string}> */
    public function searchServices(string $organizationId, string $term, string $locale): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::SEARCH_MIN_LENGTH) {
            return [];
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $this->present(
            $this->servicesQuery($organizationId)
                ->where(fn (Builder $query) => $query
                    ->where('name_en', ci_like_operator(), $pattern)
                    ->orWhere('name_am', ci_like_operator(), $pattern)
                    ->orWhere('code', ci_like_operator(), $pattern))
                ->orderBy('name_en')
                ->limit(self::SEARCH_LIMIT)
                ->get(),
            $locale,
        );
    }

    /** The service, if this institution offers it to the public now. */
    public function availableService(string $serviceId, string $organizationId): ?Service
    {
        return $this->servicesQuery($organizationId)->whereKey($serviceId)->first();
    }

    /**
     * The work units the public may name on this institution's form: its own
     * active, in-force units flagged as public-selectable (UNIT_LIST_LIMIT at
     * most). Each carries the name of its parent unit, when that parent is
     * itself in the list, so the page can show the path a visitor recognises.
     * Never a count of staff, a manager's name or any employee data.
     *
     * @return list<array{id: string, name: string, type: string|null, parent_id: string|null, parent: string|null}>
     */
    public function feedbackUnitOptions(string $organizationId, string $locale): array
    {
        $units = $this->feedbackUnitsQuery($organizationId)->orderBy('sort_order')->orderBy('name_en')->limit(self::UNIT_LIST_LIMIT)->get();
        $byId = $units->keyBy(fn (OrganizationUnit $unit): string => (string) $unit->getKey());

        return $units->map(function (OrganizationUnit $unit) use ($byId, $locale): array {
            $parent = $unit->parent_unit_id !== null ? $byId->get((string) $unit->parent_unit_id) : null;

            return [
                'id' => (string) $unit->getKey(),
                'name' => $this->localized($unit->name_en, $unit->name_am, $locale),
                'type' => $unit->unit_type,
                'parent_id' => $parent === null ? null : (string) $parent->getKey(),
                'parent' => $parent === null ? null : $this->localized($parent->name_en, $parent->name_am, $locale),
            ];
        })->values()->all();
    }

    public function hasFeedbackUnits(string $organizationId): bool
    {
        return $this->feedbackUnitsQuery($organizationId)->exists();
    }

    public function availableFeedbackUnit(string $unitId, string $organizationId): ?OrganizationUnit
    {
        return $this->feedbackUnitsQuery($organizationId)->whereKey($unitId)->first();
    }

    private function feedbackUnitsQuery(string $organizationId): Builder
    {
        $today = now()->toDateString();

        return OrganizationUnit::query()
            ->where('organization_id', $organizationId)
            ->where('is_public_feedback_selectable', true)
            ->where('status', OrganizationUnitStatus::Active->value)
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));
    }

    /**
     * @param  iterable<Service>  $services
     * @return list<array{id: string, code: string, name: string}>
     */
    private function present(iterable $services, string $locale): array
    {
        $items = [];

        foreach ($services as $service) {
            $items[] = [
                'id' => (string) $service->getKey(),
                'code' => (string) $service->code,
                'name' => $this->localized($service->name_en, $service->name_am, $locale),
            ];
        }

        return $items;
    }

    private function localized(?string $en, ?string $am, string $locale): string
    {
        return $locale === 'am' && filled($am) ? (string) $am : (string) ($en ?? $am ?? '');
    }
}
