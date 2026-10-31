<?php

declare(strict_types=1);

namespace Modules\Court\Infrastructure\Console;

use Modules\Court\Domain\Models\IntegrationClient;
use Illuminate\Console\Command;

/**
 * Issues (or rotates) the token EUISIS uses to send tribunal referrals and
 * read their updates. The plain token is printed once; only its hash is kept.
 */
class EuisisIntegrationTokenCommand extends Command
{
    protected $signature = 'court:integration-token
        {--name=EUISIS : Integration client name}
        {--ip=* : Source IP allowed to call (repeat for several; none = any)}
        {--revoke : Revoke the client instead of issuing a token}
        {--refresh-abilities : Grant the current abilities to the existing token without rotating it}';

    protected $description = 'Issue, rotate or revoke the EUISIS integration token';

    public function handle(): int
    {
        $name = (string) $this->option('name');
        $client = IntegrationClient::query()->firstOrNew(['name' => $name]);

        if ($this->option('refresh-abilities')) {
            if (! $client->exists) {
                $this->error("No integration client named {$name}.");

                return self::FAILURE;
            }
            $client->forceFill(['abilities' => IntegrationClient::DEFAULT_ABILITIES])->save();
            $this->info("{$name} may now: ".implode(', ', IntegrationClient::DEFAULT_ABILITIES).'. Its token is unchanged.');

            return self::SUCCESS;
        }

        if ($this->option('revoke')) {
            if (! $client->exists) {
                $this->error("No integration client named {$name}.");

                return self::FAILURE;
            }
            $client->forceFill(['revoked_at' => now()])->save();
            $this->info("Revoked {$name}; its token no longer works.");

            return self::SUCCESS;
        }

        $client->forceFill([
            'abilities' => IntegrationClient::DEFAULT_ABILITIES,
            'allowed_ips' => array_values(array_filter((array) $this->option('ip'))) ?: ($client->allowed_ips ?? []),
        ]);
        // Saves the client with the new token's hash (an insert for a new client).
        $token = $client->issueToken();

        $this->info(($client->wasRecentlyCreated ? 'Created' : 'Rotated')." the {$name} integration token. It is shown once:");
        $this->line($token);
        $this->line('Set it as COURT_MS_TOKEN in EUISIS. Allowed IPs: '.(($client->allowed_ips ?? []) === [] ? 'any' : implode(', ', $client->allowed_ips)));

        return self::SUCCESS;
    }
}
