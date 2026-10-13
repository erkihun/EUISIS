<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\Competency;
use App\Models\CompetencyLevel;
use App\Models\EducationLevel;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionQualificationRequirement;
use App\Models\PositionWorkLink;
use App\Models\User;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkQualificationRequirement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The position side of the design (brief §59–63, 67–70): the profile
 * (versioned), the work a position is responsible for, and its competency
 * and qualification requirements — derived from the linked work or written.
 */
class PositionProfileService
{
    public const FIELDS = ['purpose_en', 'purpose_am', 'reports_to_position_id', 'working_conditions_en', 'working_conditions_am', 'authorities_en', 'authorities_am', 'relationships_en', 'relationships_am', 'effective_from', 'effective_to'];

    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    // ── Profile versions ─────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createDraft(User $actor, Position $position, array $data): PositionProfile
    {
        $this->assertReportsTo($position, $data['reports_to_position_id'] ?? null);

        return DB::transaction(function () use ($actor, $position, $data): PositionProfile {
            // Serialise "one draft per position" on the position row.
            Position::query()->whereKey($position->getKey())->lockForUpdate()->first();
            $lineage = PositionProfile::query()->where('position_id', $position->getKey())->value('lineage_key');
            if ($lineage !== null && PositionProfile::query()->where('lineage_key', $lineage)->where('status', OrganizationDesignStatus::Draft)->exists()) {
                throw ValidationException::withMessages(['status' => __('organization-design.profile_draft_exists')]);
            }
            $next = $lineage === null ? 1 : (int) PositionProfile::query()->where('lineage_key', $lineage)->max('version_no') + 1;
            $profile = new PositionProfile([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'position_id' => $position->getKey(), 'organization_id' => $position->organization_id, 'lineage_key' => $lineage ?? (string) Str::uuid(), 'version_no' => $next,
                'status' => OrganizationDesignStatus::Draft, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $profile->save();
            $this->audit->record(AuditEventType::PositionProfileCreated, $actor, $profile, $profile->only(self::FIELDS));
            $this->audit->transition($actor, $profile, null, OrganizationDesignStatus::Draft->value);

            return $profile;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(User $actor, PositionProfile $profile, array $data): PositionProfile
    {
        $this->assertDraft($profile);
        if (array_key_exists('reports_to_position_id', $data)) {
            $this->assertReportsTo($profile->position, $data['reports_to_position_id']);
        }
        $old = $profile->only(self::FIELDS);
        $profile->fill([...array_intersect_key($data, array_flip(self::FIELDS)), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::PositionProfileUpdated, $actor, $profile, $profile->only(self::FIELDS), $old);

        return $profile;
    }

    /** @param array<string, mixed> $changes */
    public function newVersion(User $actor, PositionProfile $published, array $changes): PositionProfile
    {
        if ($published->status !== OrganizationDesignStatus::Published) {
            throw ValidationException::withMessages(['status' => __('organization-design.profile_version_from_published_only')]);
        }

        return $this->createDraft($actor, $published->position, [...$published->only(self::FIELDS), 'effective_to' => null, ...array_intersect_key($changes, array_flip(self::FIELDS))]);
    }

    public function publish(User $actor, PositionProfile $profile): PositionProfile
    {
        $this->assertDraft($profile);
        if ($profile->created_by !== null && (int) $profile->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.profile_publish_same_person')]);
        }

        return DB::transaction(function () use ($actor, $profile): PositionProfile {
            $inForce = PositionProfile::query()->where('lineage_key', $profile->lineage_key)->where('status', OrganizationDesignStatus::Published)->lockForUpdate()->first();
            $from = Carbon::parse($profile->effective_from);
            if ($inForce !== null && $from->lte(Carbon::parse($inForce->effective_from))) {
                throw ValidationException::withMessages(['effective_from' => __('organization-design.profile_version_must_start_later')]);
            }
            if ($inForce !== null) {
                $inForce->forceFill(['status' => OrganizationDesignStatus::Superseded, 'effective_to' => $from->copy()->subDay()->toDateString(), 'updated_by' => $actor->getKey()])->save();
                $this->audit->transition($actor, $inForce, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value);
            }
            $profile->forceFill(['status' => OrganizationDesignStatus::Published, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::PositionProfilePublished, $actor, $profile, ['version_no' => $profile->version_no]);
            $this->audit->transition($actor, $profile, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Published->value);

            return $profile;
        });
    }

    public function cancel(User $actor, PositionProfile $profile, ?string $reason = null): PositionProfile
    {
        $this->assertDraft($profile);
        $profile->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $profile, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Cancelled->value, $reason);

        return $profile;
    }

    /** The profile in force on a date (published or superseded covering the date), else null. */
    public function inForceFor(Position $position, ?string $date = null): ?PositionProfile
    {
        $on = $date ?? now()->toDateString();

        return PositionProfile::query()->where('position_id', $position->getKey())
            ->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
            ->whereDate('effective_from', '<=', $on)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('version_no')->first();
    }

    // ── Work links ───────────────────────────────────────────────────────

    /**
     * Replace the position's links to the work design; objects must belong
     * to the position's organization.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function syncWorkLinks(User $actor, Position $position, array $items): void
    {
        $rows = [];
        $seen = [];
        foreach (array_values($items) as $i => $item) {
            $type = $item['object_type'] ?? '';
            $model = PositionWorkLink::OBJECT_MODELS[$type] ?? null;
            if ($model === null) {
                throw ValidationException::withMessages(["items.{$i}.object_type" => __('organization-design.work_link_type_invalid')]);
            }
            $key = $type.'|'.($item['object_id'] ?? '');
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["items.{$i}.object_id" => __('organization-design.work_link_duplicate')]);
            }
            if (! $model::query()->whereKey($item['object_id'] ?? null)->where('organization_id', $position->organization_id)->exists()) {
                throw ValidationException::withMessages(["items.{$i}.object_id" => __('organization-design.work_link_foreign')]);
            }
            $seen[$key] = true;
            $rows[] = ['object_type' => $type, 'object_id' => $item['object_id'], 'is_primary' => (bool) ($item['is_primary'] ?? false), 'sequence' => $i];
        }
        DB::transaction(function () use ($actor, $position, $rows): void {
            PositionWorkLink::query()->where('position_id', $position->getKey())->delete();
            foreach ($rows as $row) {
                PositionWorkLink::query()->create([...$row, 'position_id' => $position->getKey(), 'organization_id' => $position->organization_id, 'created_by' => $actor->getKey()]);
            }
            $this->audit->record(AuditEventType::PositionWorkLinksSynced, $actor, $position, ['rows' => count($rows)]);
        });
    }

    // ── Requirements ─────────────────────────────────────────────────────

    /**
     * Replace the written (manual) requirements; derived rows are untouched.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncRequirements(User $actor, Position $position, array $data): void
    {
        DB::transaction(function () use ($actor, $position, $data): void {
            if (array_key_exists('competencies', $data)) {
                PositionCompetencyRequirement::query()->where('position_id', $position->getKey())->where('source', 'manual')->delete();
                foreach (array_values($data['competencies']) as $i => $row) {
                    $competency = Competency::query()->whereKey($row['competency_id'] ?? null)->where('is_active', true)->first();
                    $level = CompetencyLevel::query()->whereKey($row['minimum_level_id'] ?? null)->where('is_active', true)->first();
                    if ($competency === null) {
                        throw ValidationException::withMessages(["competencies.{$i}.competency_id" => __('organization-design.requirement_competency_invalid')]);
                    }
                    if ($level === null) {
                        throw ValidationException::withMessages(["competencies.{$i}.minimum_level_id" => __('organization-design.requirement_level_invalid')]);
                    }
                    PositionCompetencyRequirement::query()->updateOrCreate(
                        ['position_id' => $position->getKey(), 'competency_id' => $competency->getKey()],
                        ['organization_id' => $position->organization_id, 'minimum_level_id' => $level->getKey(), 'is_mandatory' => (bool) ($row['is_mandatory'] ?? true), 'source' => 'manual', 'sequence' => $i],
                    );
                }
            }
            if (array_key_exists('qualifications', $data)) {
                PositionQualificationRequirement::query()->where('position_id', $position->getKey())->where('source', 'manual')->delete();
                foreach (array_values($data['qualifications']) as $i => $row) {
                    $level = EducationLevel::query()->whereKey($row['education_level_id'] ?? null)->where('is_active', true)->first();
                    if ($level === null) {
                        throw ValidationException::withMessages(["qualifications.{$i}.education_level_id" => __('organization-design.requirement_education_invalid')]);
                    }
                    PositionQualificationRequirement::query()->create([
                        'position_id' => $position->getKey(), 'organization_id' => $position->organization_id, 'education_level_id' => $level->getKey(),
                        'field_of_study' => $row['field_of_study'] ?? null, 'certification' => $row['certification'] ?? null, 'license' => $row['license'] ?? null,
                        'experience_years' => (int) ($row['experience_years'] ?? 0), 'is_mandatory' => (bool) ($row['is_mandatory'] ?? true), 'source' => 'manual', 'sequence' => $i,
                    ]);
                }
            }
            $this->audit->record(AuditEventType::PositionRequirementsSynced, $actor, $position, ['competencies' => isset($data['competencies']) ? count($data['competencies']) : null, 'qualifications' => isset($data['qualifications']) ? count($data['qualifications']) : null]);
        });
    }

    /**
     * Derive requirements from the linked tasks and activities: each
     * competency at the highest level any of them needs (mandatory if any
     * says so); qualifications merged on their typed columns with the
     * longest experience. Written rows win over derived ones. Idempotent.
     *
     * @return array{competencies: int, qualifications: int}
     */
    public function deriveRequirements(User $actor, Position $position): array
    {
        $links = PositionWorkLink::query()->where('position_id', $position->getKey())->get();
        $taskIds = $links->where('object_type', 'task')->pluck('object_id')->all();
        $activityIds = $links->where('object_type', 'activity')->pluck('object_id')->all();
        $subject = fn ($q) => $q->whereIn('task_id', $taskIds)->orWhereIn('activity_id', $activityIds);

        $competencies = [];
        foreach (WorkCompetencyRequirement::query()->where($subject)->with('level:id,rank')->get() as $row) {
            $current = $competencies[$row->competency_id] ?? null;
            $mandatory = ($current['mandatory'] ?? false) || (bool) $row->is_mandatory;
            if ($current === null || $row->level->rank > $current['rank']) {
                $competencies[$row->competency_id] = ['level_id' => $row->minimum_level_id, 'rank' => $row->level->rank, 'mandatory' => $mandatory];
            } else {
                $competencies[$row->competency_id]['mandatory'] = $mandatory;
            }
        }
        $qualifications = [];
        foreach (WorkQualificationRequirement::query()->where($subject)->get() as $row) {
            $key = implode('|', [$row->education_level_id, $row->field_of_study, $row->certification, $row->license]);
            $current = $qualifications[$key] ?? null;
            if ($current === null) {
                $qualifications[$key] = $row->only(['education_level_id', 'field_of_study', 'certification', 'license', 'experience_years']) + ['is_mandatory' => (bool) $row->is_mandatory];
            } else {
                $qualifications[$key]['experience_years'] = max($current['experience_years'], $row->experience_years);
                $qualifications[$key]['is_mandatory'] = $current['is_mandatory'] || (bool) $row->is_mandatory;
            }
        }

        return DB::transaction(function () use ($actor, $position, $competencies, $qualifications): array {
            PositionCompetencyRequirement::query()->where('position_id', $position->getKey())->where('source', 'derived')->delete();
            $manual = PositionCompetencyRequirement::query()->where('position_id', $position->getKey())->pluck('competency_id')->flip();
            $i = 0;
            foreach ($competencies as $competencyId => $c) {
                if ($manual->has($competencyId)) {
                    continue;
                }
                PositionCompetencyRequirement::query()->create(['position_id' => $position->getKey(), 'organization_id' => $position->organization_id, 'competency_id' => $competencyId, 'minimum_level_id' => $c['level_id'], 'is_mandatory' => $c['mandatory'], 'source' => 'derived', 'sequence' => 1000 + $i++]);
            }
            PositionQualificationRequirement::query()->where('position_id', $position->getKey())->where('source', 'derived')->delete();
            $j = 0;
            foreach ($qualifications as $q) {
                PositionQualificationRequirement::query()->create([...$q, 'position_id' => $position->getKey(), 'organization_id' => $position->organization_id, 'source' => 'derived', 'sequence' => 1000 + $j++]);
            }
            $this->audit->record(AuditEventType::PositionRequirementsDerived, $actor, $position, ['competencies' => $i, 'qualifications' => $j]);

            return ['competencies' => $i, 'qualifications' => $j];
        });
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function assertReportsTo(Position $position, ?string $reportsTo): void
    {
        if ($reportsTo === null || $reportsTo === '') {
            return;
        }
        if ($reportsTo === $position->getKey() || ! Position::query()->whereKey($reportsTo)->where('organization_id', $position->organization_id)->exists()) {
            throw ValidationException::withMessages(['reports_to_position_id' => __('organization-design.profile_reports_to_invalid')]);
        }
    }

    private function assertDraft(PositionProfile $profile): void
    {
        if ($profile->status !== OrganizationDesignStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('organization-design.profile_not_draft')]);
        }
    }
}
