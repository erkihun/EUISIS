<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CafeteriaProvider;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LinkCafeteriaProvidersToServiceProviders extends Command
{
    protected $signature = 'cafeteria:link-service-providers';

    protected $description = 'Create a ServiceProvider registry entry for each CafeteriaProvider that lacks one, establishing shared identity';

    public function handle(): int
    {
        $cafeteriaType = ServiceType::where('code', 'cafeteria')->first();

        if (! $cafeteriaType) {
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
            // Ensure code uniqueness in service_providers — prefix if collision exists
            $code = $cafProvider->code;
            if (ServiceProvider::where('code', $code)->exists()) {
                $code = 'CAF-' . $code . '-' . Str::upper(Str::random(4));
            }

            $serviceProvider = ServiceProvider::query()->create([
                'service_type_id' => $cafeteriaType->id,
                'organization_id' => $cafProvider->organization_id,
                'name'            => $cafProvider->name_en,
                'code'            => $code,
                'status'          => $cafProvider->is_active ? 'active' : 'inactive',
                'is_demo'         => false,
            ]);

            $cafProvider->withoutTimestamps(fn () => $cafProvider->updateQuietly([
                'service_provider_id' => $serviceProvider->id,
            ]));

            $this->line("  ✓ {$cafProvider->name_en}  ({$cafProvider->code})  →  service_provider: {$serviceProvider->id}");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
