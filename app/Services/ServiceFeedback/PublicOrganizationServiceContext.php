<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationUnitStatus;
use App\Enums\OrganizationUnitType;
use App\Models\OrganizationUnit;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;

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
 * - Offices (service points) are the institution's own active organization
 *   units of type "office", and the units that replaced its former
 *   institution offices (institution_office_id set) — not its internal
 *   directorates and teams. They are offered only when it has more than one;
 *   with a single office there is nothing to choose. Which units count as
 *   public service points is NEEDS_POLICY_DECISION; until a unit carries its
 *   own "public service point" flag, this is the rule.
 */
final class PublicOrganizationServiceContext
{
    /** Up to this many published services travel with the page; more are searched. */
    public const LIST_LIMIT = 150;

    public const SEARCH_LIMIT = 20;

    public const SEARCH_MIN_LENGTH = 2;

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
     * The institution's service points, when it has more than one.
     *
     * @return list<array{id: string, name: string}>
     */
    public function officeOptions(string $organizationId, string $locale): array
    {
        $offices = $this->officesQuery($organizationId)->orderBy('sort_order')->orderBy('name_en')->limit(200)->get();

        if ($offices->count() < 2) {
            return [];
        }

        return $offices->map(fn (OrganizationUnit $unit): array => [
            'id' => (string) $unit->getKey(),
            'name' => $this->localized($unit->name_en, $unit->name_am, $locale),
        ])->values()->all();
    }

    public function availableOffice(string $unitId, string $organizationId): ?OrganizationUnit
    {
        return $this->officesQuery($organizationId)->whereKey($unitId)->first();
    }

    private function officesQuery(string $organizationId): Builder
    {
        return OrganizationUnit::query()
            ->where('organization_id', $organizationId)
            ->where('status', OrganizationUnitStatus::Active->value)
            ->where(fn (Builder $query) => $query
                ->where('unit_type', OrganizationUnitType::Office->value)
                ->orWhereNotNull('institution_office_id'));
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
