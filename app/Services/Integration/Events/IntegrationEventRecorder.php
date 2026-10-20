<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

use App\Models\Integration\IntegrationEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Writes one outbox row inside the caller's database transaction.
 *
 * The caller (model observer or domain action) runs within the transaction
 * that changes the master record — HasIntegrationEvents wraps every save in
 * one — so the row commits with the change or not at all. Nothing here talks
 * to a queue or the network: a partner or Redis outage can never fail or lose
 * a valid master-data change.
 *
 * The per-aggregate sequence is taken from integration_aggregate_versions
 * under a row lock, so two transactions touching the same aggregate get
 * consecutive numbers in commit order.
 */
final class IntegrationEventRecorder
{
    public function __construct(private readonly IntegrationEventContext $context) {}

    public static function enabled(): bool
    {
        return (bool) config('integration.events.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $data  whitelisted payload (EventCatalog::dataKeys)
     */
    public function record(
        string $type,
        string $aggregateUuid,
        array $data,
        ?string $organizationId,
        ?string $relatedOrganizationId = null,
        bool $global = false,
        ?string $effectiveAt = null,
        ?string $causationId = null,
    ): ?IntegrationEvent {
        if (! self::enabled() || $this->context->isSuppressed($type, $aggregateUuid)) {
            return null;
        }

        if (! EventCatalog::exists($type)) {
            throw new LogicException("Unknown integration event type [{$type}].");
        }

        if (DB::transactionLevel() === 0) {
            // Never write an outbox row outside the domain transaction.
            return DB::transaction(fn (): ?IntegrationEvent => $this->record($type, $aggregateUuid, $data, $organizationId, $relatedOrganizationId, $global, $effectiveAt, $causationId));
        }

        $aggregateType = EventCatalog::aggregate($type);
        $now = Carbon::now();

        return IntegrationEvent::query()->create([
            'event_id' => (string) Str::uuid7(),
            'event_type' => $type,
            'event_version' => EventCatalog::VERSION,
            'aggregate_type' => $aggregateType,
            'aggregate_uuid' => $aggregateUuid,
            'sequence' => $this->nextSequence($aggregateType, $aggregateUuid),
            'organization_id' => $organizationId,
            'related_organization_id' => $relatedOrganizationId !== $organizationId ? $relatedOrganizationId : null,
            'is_global' => $global,
            'change_kind' => EventCatalog::isTombstone($type, $data) ? IntegrationEvent::KIND_TOMBSTONE : IntegrationEvent::KIND_UPSERT,
            'occurred_at' => $now,
            'effective_at' => $effectiveAt,
            'correlation_id' => $this->context->correlationId(),
            'causation_id' => $causationId,
            'payload' => $data,
            'metadata' => $this->metadata(),
            'status' => IntegrationEvent::STATUS_PENDING,
            'available_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function nextSequence(string $aggregateType, string $aggregateUuid): int
    {
        $key = ['aggregate_type' => $aggregateType, 'aggregate_uuid' => $aggregateUuid];

        $current = DB::table('integration_aggregate_versions')->where($key)->lockForUpdate()->value('version');

        if ($current === null) {
            try {
                // Savepoint: on PostgreSQL a failed insert would otherwise abort
                // the whole domain transaction.
                DB::transaction(fn () => DB::table('integration_aggregate_versions')->insert([...$key, 'version' => 1, 'updated_at' => now()]));

                return 1;
            } catch (UniqueConstraintViolationException) {
                // A concurrent first event for the same aggregate won the insert.
                $current = DB::table('integration_aggregate_versions')->where($key)->lockForUpdate()->value('version');
            }
        }

        $next = (int) $current + 1;
        DB::table('integration_aggregate_versions')->where($key)->update(['version' => $next, 'updated_at' => now()]);

        return $next;
    }

    /** Internal diagnostics only — never serialised to partners. @return array<string, mixed> */
    private function metadata(): array
    {
        $user = auth()->user();

        return array_filter([
            'request_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
            'actor_user_id' => $user instanceof User ? $user->getKey() : null,
            'source' => app()->runningInConsole() ? 'console' : 'http',
        ], fn ($value): bool => $value !== null);
    }
}
