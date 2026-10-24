<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Integration\Events\IntegrationEventRecorder;
use App\Services\Integration\Events\MasterDataEventProjector;
use Illuminate\Support\Facades\DB;

/**
 * Master-data models whose changes are integration events.
 *
 * The observer (MasterDataEventProjector) writes the outbox row from the
 * model's own `created` / `updated` / `deleted` / `restored` events. For that
 * row to commit atomically with the change, a save or delete that is not
 * already inside a transaction is wrapped in one here. Inside an existing
 * transaction (every domain action that already uses DB::transaction) nothing
 * changes.
 *
 * Query-builder writes (`Model::query()->update([...])`) bypass model events
 * and therefore produce no event; master-data code must use model saves.
 */
trait HasIntegrationEvents
{
    public static function bootHasIntegrationEvents(): void
    {
        // Registered once booting is over: Laravel 13 refuses to instantiate a
        // model (as observe() does) while it is still booting.
        static::whenBooted(fn () => static::observe(MasterDataEventProjector::class));
    }

    public function save(array $options = []): bool
    {
        if (! IntegrationEventRecorder::enabled() || DB::connection($this->getConnectionName())->transactionLevel() > 0) {
            return parent::save($options);
        }

        return DB::connection($this->getConnectionName())->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        if (! IntegrationEventRecorder::enabled() || DB::connection($this->getConnectionName())->transactionLevel() > 0) {
            return parent::delete();
        }

        return DB::connection($this->getConnectionName())->transaction(fn (): ?bool => parent::delete());
    }
}
