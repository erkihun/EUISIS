<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\IsicActivity;
use App\Models\OrganizationFunction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The service catalog (brief §9–11, 16). A service is justified by one or
 * more functions of its own organization and may cite ISIC at service level.
 * Versioned like mandates: drafts change, published versions never do, and
 * the person who drafted a version does not publish it.
 */
class ServiceCatalogService
{
    public const FIELDS = [
        'code', 'name_en', 'name_am', 'description_en', 'description_am', 'service_type_id', 'service_classification_id', 'cpc_classification_id',
        'beneficiary_type', 'delivery_channel', 'legal_basis', 'owner_organization_unit_id', 'effective_from', 'effective_to',
    ];

    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    /** @param array<string, mixed> $data */
    public function createDraft(User $actor, string $organizationId, array $data): Service
    {
        return DB::transaction(function () use ($actor, $organizationId, $data): Service {
            $service = new Service([
                ...$this->only($data),
                'organization_id' => $organizationId,
                'lineage_key' => (string) Str::uuid(),
                'version_no' => 1,
                'status' => OrganizationDesignStatus::Draft,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $service->save();
            $this->syncLinks($service, $data);

            $this->audit->record(AuditEventType::ServiceCreated, $actor, $service, $service->only(self::FIELDS));
            $this->audit->transition($actor, $service, null, OrganizationDesignStatus::Draft->value);

            return $service;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(User $actor, Service $service, array $data): Service
    {
        $this->assertDraft($service);

        return DB::transaction(function () use ($actor, $service, $data): Service {
            $old = $service->only(self::FIELDS);
            $service->fill([...$this->only($data), 'updated_by' => $actor->getKey()])->save();
            $this->syncLinks($service, $data);
            $this->audit->record(AuditEventType::ServiceUpdated, $actor, $service, $service->only(self::FIELDS), $old);

            return $service;
        });
    }

    /** @param array<string, mixed> $changes */
    public function newVersion(User $actor, Service $published, array $changes): Service
    {
        if ($published->status !== OrganizationDesignStatus::Published) {
            throw ValidationException::withMessages(['status' => __('organization-design.service_version_from_published_only')]);
        }

        return DB::transaction(function () use ($actor, $published, $changes): Service {
            Service::query()->whereKey($published->getKey())->lockForUpdate()->first();
            if (Service::query()->where('lineage_key', $published->lineage_key)->where('status', OrganizationDesignStatus::Draft)->exists()) {
                throw ValidationException::withMessages(['status' => __('organization-design.service_draft_exists')]);
            }
            $next = (int) Service::query()->where('lineage_key', $published->lineage_key)->max('version_no') + 1;

            $draft = new Service([
                ...$published->only(self::FIELDS),
                ...$this->only($changes),
                'organization_id' => $published->organization_id,
                'lineage_key' => $published->lineage_key,
                'version_no' => $next,
                'status' => OrganizationDesignStatus::Draft,
                'effective_to' => null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $draft->save();
            // Links carry over unless the new version says otherwise.
            $this->syncLinks($draft, [
                'function_ids' => $changes['function_ids'] ?? $published->functions()->pluck('organization_functions.id')->all(),
                'isic_activity_ids' => $changes['isic_activity_ids'] ?? $published->isicActivities()->pluck('isic_activities.id')->all(),
            ]);

            $this->audit->record(AuditEventType::ServiceCreated, $actor, $draft, ['version_no' => $next, 'supersedes' => $published->getKey()]);
            $this->audit->transition($actor, $draft, null, OrganizationDesignStatus::Draft->value);

            return $draft;
        });
    }

    public function publish(User $actor, Service $service): Service
    {
        $this->assertDraft($service);
        if ($service->created_by !== null && (int) $service->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.service_publish_same_person')]);
        }

        return DB::transaction(function () use ($actor, $service): Service {
            $inForce = Service::query()->where('lineage_key', $service->lineage_key)->where('status', OrganizationDesignStatus::Published)->lockForUpdate()->first();
            $from = Carbon::parse($service->effective_from);
            if ($inForce !== null && $from->lte(Carbon::parse($inForce->effective_from))) {
                throw ValidationException::withMessages(['effective_from' => __('organization-design.service_version_must_start_later')]);
            }
            if ($inForce !== null) {
                $inForce->forceFill(['status' => OrganizationDesignStatus::Superseded, 'effective_to' => $from->copy()->subDay()->toDateString(), 'updated_by' => $actor->getKey()])->save();
                $this->audit->record(AuditEventType::ServiceSuperseded, $actor, $inForce, ['superseded_by' => $service->getKey()]);
                $this->audit->transition($actor, $inForce, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value);
            }
            $service->forceFill(['status' => OrganizationDesignStatus::Published, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::ServicePublished, $actor, $service, ['version_no' => $service->version_no]);
            $this->audit->transition($actor, $service, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Published->value);

            return $service;
        });
    }

    public function cancel(User $actor, Service $service, ?string $reason = null): Service
    {
        $this->assertDraft($service);

        return DB::transaction(function () use ($actor, $service, $reason): Service {
            $service->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::ServiceCancelled, $actor, $service, [], null, $reason);
            $this->audit->transition($actor, $service, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Cancelled->value, $reason);

            return $service;
        });
    }

    /**
     * Function and ISIC links. Functions must belong to the service's
     * organization (the request checks too; this is the last line).
     *
     * @param  array<string, mixed>  $data
     */
    private function syncLinks(Service $service, array $data): void
    {
        if (array_key_exists('function_ids', $data)) {
            $ids = array_values(array_unique(array_filter((array) $data['function_ids'])));
            $own = OrganizationFunction::query()->whereKey($ids)->where('organization_id', $service->organization_id)->pluck('id')->all();
            if (count($own) !== count($ids)) {
                throw ValidationException::withMessages(['function_ids' => __('organization-design.service_function_foreign')]);
            }
            $service->functions()->sync(collect($own)->mapWithKeys(fn ($id) => [$id => ['id' => (string) Str::uuid(), 'organization_id' => $service->organization_id]])->all());
        }
        if (array_key_exists('isic_activity_ids', $data)) {
            $ids = array_values(array_unique(array_filter((array) $data['isic_activity_ids'])));
            $known = IsicActivity::query()->whereKey($ids)->pluck('id')->all();
            if (count($known) !== count($ids)) {
                throw ValidationException::withMessages(['isic_activity_ids' => __('organization-design.isic_unknown')]);
            }
            $service->isicActivities()->sync(collect($known)->mapWithKeys(fn ($id) => [$id => ['id' => (string) Str::uuid()]])->all());
        }
    }

    private function assertDraft(Service $service): void
    {
        if (! $service->status->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.service_not_draft')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }
}
