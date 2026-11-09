<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\InstitutionMandate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Institution mandates (brief §3–5). History is never rewritten:
 *  - a draft may be edited or cancelled; a published version never is;
 *  - a new version copies the published one into a draft (version + 1);
 *  - publishing a version supersedes the version in force on the day before
 *    its effective date, so exactly one version is in force on any date;
 *  - the person who drafted a version never publishes it.
 */
class MandateService
{
    /** Fields a designer writes. */
    public const FIELDS = [
        'code', 'title_en', 'title_am', 'description_en', 'description_am',
        'legal_instrument_type_id', 'legal_instrument_number', 'legal_reference', 'article_reference', 'issuing_authority',
        'legal_source_id',
        'effective_from', 'effective_to',
    ];

    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    /** @param array<string, mixed> $data */
    public function createDraft(User $actor, string $organizationId, array $data): InstitutionMandate
    {
        return DB::transaction(function () use ($actor, $organizationId, $data): InstitutionMandate {
            $mandate = new InstitutionMandate([
                ...$this->only($data),
                'organization_id' => $organizationId,
                'lineage_key' => (string) Str::uuid(),
                'version_no' => 1,
                'status' => OrganizationDesignStatus::Draft,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $mandate->save();

            $this->audit->record(AuditEventType::MandateCreated, $actor, $mandate, $mandate->only(self::FIELDS));
            $this->audit->transition($actor, $mandate, null, OrganizationDesignStatus::Draft->value);

            return $mandate;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(User $actor, InstitutionMandate $mandate, array $data): InstitutionMandate
    {
        $this->assertDraft($mandate);

        return DB::transaction(function () use ($actor, $mandate, $data): InstitutionMandate {
            $old = $mandate->only(self::FIELDS);
            $mandate->fill([...$this->only($data), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::MandateUpdated, $actor, $mandate, $mandate->only(self::FIELDS), $old);

            return $mandate;
        });
    }

    /**
     * A new draft version of a published mandate. The draft carries the
     * published content; `$changes` overrides what the new instrument changes.
     *
     * @param  array<string, mixed>  $changes
     */
    public function newVersion(User $actor, InstitutionMandate $published, array $changes): InstitutionMandate
    {
        if ($published->status !== OrganizationDesignStatus::Published) {
            throw ValidationException::withMessages(['status' => __('organization-design.mandate_version_from_published_only')]);
        }

        return DB::transaction(function () use ($actor, $published, $changes): InstitutionMandate {
            // Serialise version numbering per lineage.
            InstitutionMandate::query()->whereKey($published->getKey())->lockForUpdate()->first();
            if (InstitutionMandate::query()->where('lineage_key', $published->lineage_key)->where('status', OrganizationDesignStatus::Draft)->exists()) {
                throw ValidationException::withMessages(['status' => __('organization-design.mandate_draft_exists')]);
            }
            $next = (int) InstitutionMandate::query()->where('lineage_key', $published->lineage_key)->max('version_no') + 1;

            $draft = new InstitutionMandate([
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

            $this->audit->record(AuditEventType::MandateCreated, $actor, $draft, ['version_no' => $next, 'supersedes' => $published->getKey()]);
            $this->audit->transition($actor, $draft, null, OrganizationDesignStatus::Draft->value);

            return $draft;
        });
    }

    public function publish(User $actor, InstitutionMandate $mandate): InstitutionMandate
    {
        $this->assertDraft($mandate);
        if ($mandate->created_by !== null && (int) $mandate->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.mandate_publish_same_person')]);
        }

        return DB::transaction(function () use ($actor, $mandate): InstitutionMandate {
            $inForce = InstitutionMandate::query()
                ->where('lineage_key', $mandate->lineage_key)
                ->where('status', OrganizationDesignStatus::Published)
                ->lockForUpdate()
                ->first();

            $from = Carbon::parse($mandate->effective_from);
            if ($inForce !== null && $from->lte(Carbon::parse($inForce->effective_from))) {
                throw ValidationException::withMessages(['effective_from' => __('organization-design.mandate_version_must_start_later')]);
            }

            if ($inForce !== null) {
                $inForce->forceFill([
                    'status' => OrganizationDesignStatus::Superseded,
                    'effective_to' => $from->copy()->subDay()->toDateString(),
                    'updated_by' => $actor->getKey(),
                ])->save();
                $this->audit->record(AuditEventType::MandateSuperseded, $actor, $inForce, ['superseded_by' => $mandate->getKey()]);
                $this->audit->transition($actor, $inForce, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value);
            }

            $mandate->forceFill([
                'status' => OrganizationDesignStatus::Published,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();
            $this->audit->record(AuditEventType::MandatePublished, $actor, $mandate, ['version_no' => $mandate->version_no]);
            $this->audit->transition($actor, $mandate, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Published->value);

            return $mandate;
        });
    }

    public function cancel(User $actor, InstitutionMandate $mandate, ?string $reason = null): InstitutionMandate
    {
        $this->assertDraft($mandate);

        return DB::transaction(function () use ($actor, $mandate, $reason): InstitutionMandate {
            $mandate->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::MandateCancelled, $actor, $mandate, [], null, $reason);
            $this->audit->transition($actor, $mandate, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Cancelled->value, $reason);

            return $mandate;
        });
    }

    private function assertDraft(InstitutionMandate $mandate): void
    {
        if (! $mandate->status->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.mandate_not_draft')]);
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
