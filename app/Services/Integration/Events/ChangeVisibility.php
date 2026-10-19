<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

use App\Models\ExternalApplication;
use App\Models\Integration\IntegrationEvent;
use App\Services\Integration\IntegrationAccessService;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Which integration events a partner application may see, and in what form.
 *
 *   type   the application must hold the event's domain scope
 *          (EventCatalog::scope) — no employees.basic_read, no employee.*;
 *   where  the event's organization (or, for moves/transfers, its previous
 *          organization) must be inside the application's organization bound
 *          (IntegrationAccessService); global events (hierarchy version
 *          published) need only the type scope.
 *
 * Both are applied in SQL, before anything is serialised — the feed never
 * loads city-wide rows to filter them in PHP.
 *
 * Transfers across the bound: a partner that sees only the SOURCE
 * organization receives a minimised variant that says the employee left,
 * without the destination organization, unit, position or assignment
 * (`visibility: "source_only"`). Whether a source partner should learn the
 * destination is NEEDS_POLICY_DECISION_PARTNER_TRANSFER_EVENT_VISIBILITY;
 * until decided, it does not.
 */
final class ChangeVisibility
{
    /** Destination fields withheld from a source-only viewer. */
    private const DESTINATION_FIELDS = ['assignment_uuid', 'organization_uuid', 'unit_uuid', 'position_uuid', 'parent_unit_uuid'];

    private const NO_ORGANIZATION = '00000000-0000-0000-0000-000000000000';

    /**
     * Bounds memoised for the life of this instance — one publisher run or
     * one feed request — so fanning out thousands of events does not re-expand
     * the same grants per event. A new run always starts fresh.
     *
     * @var array<string, list<string>|null>
     */
    private array $bounds = [];

    /** @var array<string, list<string>> */
    private array $types = [];

    public function __construct(private readonly IntegrationAccessService $access) {}

    /** @return list<string>|null */
    private function organizationIds(ExternalApplication $application): ?array
    {
        $key = (string) $application->getKey();
        if (! array_key_exists($key, $this->bounds)) {
            $this->bounds[$key] = $this->access->organizationIdsFor($application);
        }

        return $this->bounds[$key];
    }

    /** @return list<string> */
    public function allowedTypes(ExternalApplication $application): array
    {
        return $this->types[(string) $application->getKey()] ??= EventCatalog::typesForScopes($application->grantableScopes());
    }

    /**
     * @param  list<string>|null  $types  requested types (already intersected with allowedTypes)
     */
    public function constrain(Builder $query, ExternalApplication $application, array $types): Builder
    {
        $query->whereIn('event_type', $types === [] ? ['__none__'] : $types);

        $ids = $this->organizationIds($application);
        if ($ids === null) {
            return $query;
        }

        $ids = $ids === [] ? [self::NO_ORGANIZATION] : $ids;

        return $query->where(fn ($inner) => $inner
            ->where('is_global', true)
            ->orWhereIn('organization_id', $ids)
            ->orWhereIn('related_organization_id', $ids));
    }

    public function canSee(ExternalApplication $application, IntegrationEvent $event): bool
    {
        if (! in_array($event->event_type, $this->allowedTypes($application), true)) {
            return false;
        }
        if ($event->is_global) {
            return true;
        }

        $ids = $this->organizationIds($application);

        return $ids === null
            || ($event->organization_id !== null && in_array((string) $event->organization_id, $ids, true))
            || ($event->related_organization_id !== null && in_array((string) $event->related_organization_id, $ids, true));
    }

    /**
     * The external envelope (docs/integration/event-catalog.md). Internal
     * metadata (actor, request) is never included.
     *
     * @return array<string, mixed>
     */
    public function present(IntegrationEvent $event, ExternalApplication $application): array
    {
        $data = $event->payload ?? [];
        $organizationUuid = $event->organization_id;

        if ($this->sourceOnly($event, $application)) {
            foreach (self::DESTINATION_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = null;
                }
            }
            if (array_key_exists('visibility', $data)) {
                $data['visibility'] = 'source_only';
            }
            $organizationUuid = $event->related_organization_id;
        }

        return self::envelope($event, $data, $organizationUuid);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function envelope(IntegrationEvent $event, array $data, ?string $organizationUuid): array
    {
        return [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'event_version' => $event->event_version,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_uuid' => $event->aggregate_uuid,
            'organization_uuid' => $organizationUuid,
            'sequence' => $event->sequence,
            'change_kind' => $event->change_kind,
            'occurred_at' => $event->occurred_at?->toIso8601ZuluString('microsecond'),
            'effective_at' => $event->effective_at?->toDateString(),
            'correlation_id' => $event->correlation_id,
            'data' => $data,
        ];
    }

    private function sourceOnly(IntegrationEvent $event, ExternalApplication $application): bool
    {
        if ($event->related_organization_id === null || $event->is_global) {
            return false;
        }

        $ids = $this->organizationIds($application);
        if ($ids === null) {
            return false;
        }

        return ! ($event->organization_id !== null && in_array((string) $event->organization_id, $ids, true));
    }
}
