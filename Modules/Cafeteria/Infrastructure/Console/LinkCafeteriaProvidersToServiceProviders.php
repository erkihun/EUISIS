<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Entitlements\Contracts\Data\NewServiceProvider;
use Modules\Entitlements\Contracts\ServiceDeliveryRegistry;

class LinkCafeteriaProvidersToServiceProviders extends Command
{
    protected $signature = 'cafeteria:link-service-providers';

    protected $description = 'Create a ServiceProvider registry entry for each CafeteriaProvider that lacks one, establishing shared identity';

    public function __construct(private readonly ServiceDeliveryRegistry $serviceDelivery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->serviceDelivery->serviceTypeId('cafeteria') === null) {
            $this->error('ServiceType with code "cafeteria" not found. Run seeders first.');

            return self::FAILURE;
        }

        $unlinked = CafeteriaProvider::query()
            ->withTrashed()
            ->whereNull('service_provider_id')
            ->get();

        if ($unlinked->isEmpty()) {
            $this->info('All cafeteria providers are already linked.');

            return self::SUCCESS;
        }

        $this->info("Linking {$unlinked->count()} cafeteria provider(s)…");

        foreach ($unlinked as $cafProvider) {
            $serviceProviderId = $this->serviceDelivery->createProvider(new NewServiceProvider(
                serviceTypeCode: 'cafeteria',
                organizationId: $cafProvider->organization_id,
                name: $cafProvider->name_en,
                code: $cafProvider->code,
                status: $cafProvider->is_active ? 'active' : 'inactive',
                collisionPrefix: 'CAF',
            ));

            $cafProvider->withoutTimestamps(fn () => $cafProvider->updateQuietly([
                'service_provider_id' => $serviceProviderId,
            ]));

            $this->line("  ✓ {$cafProvider->name_en}  ({$cafProvider->code})  →  service_provider: {$serviceProviderId}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
