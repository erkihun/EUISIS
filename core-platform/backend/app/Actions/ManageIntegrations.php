<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiClient;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\DomainEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Integration Center's changes, for the API and the screens alike: API
 * clients (scopes, the part of the city they read, addresses, rate limit,
 * expiry; the secret exists in clear only in the answer that creates or
 * rotates it, and revocation ends it at once), webhook endpoints (subscribed
 * events, a signing secret shown once, on and off), and the manual retry of a
 * delivery. Every change is audited; no secret goes into an audit row.
 */
final readonly class ManageIntegrations
{
    public const CLIENT_FIELDS = ['uuid', 'name', 'description', 'scope_type', 'owner_organization_id', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'is_active',
        'expires_at', 'revoked_at', 'revocation_reason', 'secret_rotated_at', 'last_used_at', 'last_used_ip', 'created_at'];

    public const WEBHOOK_FIELDS = ['id', 'name', 'url', 'event_types', 'scope_organization_id', 'is_active'];

    public function __construct(private AuditWriter $audit) {}

    /** @return array{0: ApiClient, 1: string} the client and its secret, in clear this once */
    public function createClient(array $input, User $actor): array
    {
        $data = $this->clientData($input, true);

        return DB::transaction(function () use ($data, $actor): array {
            $client = new ApiClient($data + ['expires_at' => now()->addYear()]);
            $client->forceFill(['created_by' => $actor->id])->save();
            $secret = $client->createToken('integration', $client->scopes, $client->expires_at)->plainTextToken;
            $this->audit->writeFor('api_client.created', $actor, 'ApiClient', $client->uuid, $client->owner_organization_id, null,
                $client->only(['name', 'scopes', 'owner_organization_id', 'allowed_ips', 'rate_limit_per_minute', 'expires_at']));

            return [$client, $secret];
        });
    }

    public function updateClient(ApiClient $client, array $input, User $actor): ApiClient
    {
        $this->assertNotRevoked($client);
        $data = $this->clientData($input, false);

        return DB::transaction(function () use ($data, $actor, $client): ApiClient {
            $old = $client->only(array_keys($data));
            $client->fill($data)->save();
            // The token carries the same scopes and expiry as its client.
            $client->tokens()->update(['abilities' => json_encode($client->scopes), 'expires_at' => $client->expires_at]);
            $this->audit->writeFor('api_client.updated', $actor, 'ApiClient', $client->uuid, $client->owner_organization_id, $old, $client->only(array_keys($data)));

            return $client;
        });
    }

    /** @return string the new secret, in clear this once; the old one stops working at once */
    public function rotateClient(ApiClient $client, User $actor): string
    {
        $this->assertNotRevoked($client);

        return DB::transaction(function () use ($actor, $client): string {
            $client->tokens()->delete();
            $secret = $client->createToken('integration', $client->scopes, $client->expires_at)->plainTextToken;
            $client->forceFill(['secret_rotated_at' => now()])->save();
            $this->audit->writeFor('api_client.rotated', $actor, 'ApiClient', $client->uuid, $client->owner_organization_id);

            return $secret;
        });
    }

    public function revokeClient(ApiClient $client, array $input, User $actor): ApiClient
    {
        $this->assertNotRevoked($client);
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:1000']])->validate();

        return DB::transaction(function () use ($actor, $client, $data): ApiClient {
            $client->tokens()->delete();
            $client->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $data['reason'], 'is_active' => false])->save();
            $this->audit->writeFor('api_client.revoked', $actor, 'ApiClient', $client->uuid, $client->owner_organization_id, null, ['revoked' => true], $data['reason']);

            return $client;
        });
    }

    /** @return array{0: WebhookEndpoint, 1: string} the endpoint and its signing secret, in clear this once */
    public function createWebhook(array $input, User $actor): array
    {
        $data = $this->webhookData($input, true);

        return DB::transaction(function () use ($actor, $data): array {
            $secret = 'whsec_'.Str::random(40);
            $endpoint = new WebhookEndpoint($data);
            $endpoint->forceFill(['secret' => $secret, 'created_by' => $actor->id, 'secret_rotated_at' => now()])->save();
            $this->audit->writeFor('webhook.created', $actor, 'WebhookEndpoint', $endpoint->id, $endpoint->scope_organization_id, null, $endpoint->only(['name', 'url', 'event_types']));

            return [$endpoint, $secret];
        });
    }

    public function updateWebhook(WebhookEndpoint $webhook, array $input, User $actor): WebhookEndpoint
    {
        $data = $this->webhookData($input, false);
        if (($data['is_active'] ?? false) && $webhook->suspended_at !== null) {
            return $this->resumeWebhook($webhook->fill(array_diff_key($data, ['is_active' => true])), $actor); // switching a suspended webhook on is resuming it
        }
        $old = $webhook->only(array_keys($data));
        $webhook->fill($data)->save();
        $this->audit->writeFor('webhook.updated', $actor, 'WebhookEndpoint', $webhook->id, $webhook->scope_organization_id, $old, $webhook->only(array_keys($data)));

        return $webhook;
    }

    /** @return string the new signing secret, in clear this once */
    public function rotateWebhook(WebhookEndpoint $webhook, User $actor): string
    {
        $secret = 'whsec_'.Str::random(40);
        // The old secret keeps signing alongside for a grace period (ADR 0010), so the receiver can switch without losing events.
        $grace = app(CoreSettings::class)->int('integration.webhook_secret_grace_hours');
        $webhook->forceFill(['previous_secret' => $grace > 0 ? $webhook->secret : null, 'previous_secret_expires_at' => $grace > 0 ? now()->addHours($grace) : null,
            'secret' => $secret, 'secret_rotated_at' => now()])->save();
        $this->audit->writeFor('webhook.secret_rotated', $actor, 'WebhookEndpoint', $webhook->id, $webhook->scope_organization_id, null, ['previous_valid_hours' => $grace]);

        return $secret;
    }

    /** Resumes a suspended endpoint: its waiting deliveries are sent again from now (ADR 0010). */
    public function resumeWebhook(WebhookEndpoint $webhook, User $actor): WebhookEndpoint
    {
        if ($webhook->suspended_at === null) {
            throw ValidationException::withMessages(['webhook' => 'This webhook is not suspended.']);
        }
        DB::transaction(function () use ($webhook, $actor): void {
            $webhook->forceFill(['is_active' => true, 'consecutive_failures' => 0, 'suspended_at' => null, 'suspended_reason' => null])->save();
            WebhookDelivery::query()->where('webhook_endpoint_id', $webhook->id)->where('status', WebhookDelivery::PENDING)->update(['next_attempt_at' => now()]);
            $this->audit->writeFor('webhook.resumed', $actor, 'WebhookEndpoint', $webhook->id, $webhook->scope_organization_id);
        });

        return $webhook;
    }

    public function retryDelivery(WebhookDelivery $delivery, User $actor): WebhookDelivery
    {
        if ($delivery->status === WebhookDelivery::DELIVERED) {
            throw ValidationException::withMessages(['delivery' => 'This event was already delivered.']);
        }
        $delivery->forceFill(['status' => WebhookDelivery::PENDING, 'next_attempt_at' => now()])->save();
        $this->audit->writeFor('webhook.delivery_retried', $actor, 'WebhookDelivery', $delivery->id, null);

        return $delivery;
    }

    /** @return array<string, mixed> */
    private function clientData(array $input, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = Validator::make($input, [
            'name' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'owner_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
            'scopes' => [$required, 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiClient::SCOPES)],
            'allowed_ips' => ['nullable', 'array', 'max:50'],
            'allowed_ips.*' => ['string', 'max:64', 'regex:/^[0-9a-fA-F:.]+(\/\d{1,3})?$/'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:10', 'max:6000'],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'scope_type' => ['nullable', Rule::in([User::SCOPE_CITYWIDE, ...User::ORGANIZATION_SCOPES])],
        ])->validate();
        if (isset($data['scopes'])) {
            $data['scopes'] = array_values(array_unique($data['scopes']));
        }

        // The part of the city the client reads is explicit (ADR 0008): an organization (with
        // everything under it, or alone) or the whole city when chosen; never by omission.
        $scopeType = $data['scope_type'] ?? null;
        unset($data['scope_type']);
        if ($creating || array_key_exists('scope_type', $input) || array_key_exists('owner_organization_id', $data)) {
            $type = $scopeType ?? (($data['owner_organization_id'] ?? null) !== null ? User::SCOPE_SUBTREE : null);
            if ($type === null || ($type !== User::SCOPE_CITYWIDE && ($data['owner_organization_id'] ?? null) === null)) {
                throw ValidationException::withMessages(['owner_organization_id' => 'Choose the organization the client may read, or the whole city.']);
            }
            $data['scope_type'] = $type;
            $data['owner_organization_id'] = $type === User::SCOPE_CITYWIDE ? null : $data['owner_organization_id'];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function webhookData(array $input, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $https = app()->environment(['local', 'testing']) ? 'url:http,https' : 'url:https';

        return Validator::make($input, [
            'name' => [$required, 'string', 'max:160'],
            'url' => [$required, 'string', 'max:500', $https],
            'event_types' => [$required, 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in([...DomainEvents::types(), '*'])],
            'scope_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }

    private function assertNotRevoked(ApiClient $client): void
    {
        if ($client->revoked_at !== null) {
            throw ValidationException::withMessages(['client' => 'A revoked client stays revoked; register a new one.']);
        }
    }
}
