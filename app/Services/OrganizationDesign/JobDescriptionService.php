<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\EmployeeAssignment;
use App\Models\JobDescription;
use App\Models\JobDescriptionSection;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionQualificationRequirement;
use App\Models\PositionService;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Job descriptions (brief §64–66, 81): built from the published profile and
 * the linked work. Derived sections are regenerated from the masters while
 * the document is a draft; custom sections are written. Draft →
 * under_review → approved → published, maker ≠ checker; publishing records
 * the document on the position's current assignments.
 */
class JobDescriptionService
{
    /** Section keys in document order, with their source. */
    public const SECTIONS = [
        'purpose' => 'custom',
        'reporting' => 'derived',
        'duties' => 'derived',
        'occupation' => 'derived',
        'qualifications' => 'derived',
        'competencies' => 'derived',
        'standards' => 'derived',
        'working_conditions' => 'custom',
        'authorities' => 'custom',
        'relationships' => 'custom',
    ];

    public function __construct(private readonly OrganizationDesignAudit $audit, private readonly PositionProfileService $profiles) {}

    // ── Lifecycle ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createDraft(User $actor, Position $position, array $data): JobDescription
    {
        $profile = isset($data['position_profile_id']) ? PositionProfile::query()->whereKey($data['position_profile_id'])->first() : $this->profiles->inForceFor($position, $data['effective_from'] ?? null) ?? $this->profiles->inForceFor($position);
        if ($profile === null || $profile->position_id !== $position->getKey() || ! in_array($profile->status, [OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded], true)) {
            throw ValidationException::withMessages(['position_profile_id' => __('organization-design.jd_profile_required')]);
        }

        return DB::transaction(function () use ($actor, $position, $profile, $data): JobDescription {
            // Serialise "one document in progress per position" on the position row.
            Position::query()->whereKey($position->getKey())->lockForUpdate()->first();
            $lineage = JobDescription::query()->where('position_id', $position->getKey())->value('lineage_key');
            if ($lineage !== null && JobDescription::query()->where('lineage_key', $lineage)->whereIn('status', [OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Approved->value])->exists()) {
                throw ValidationException::withMessages(['status' => __('organization-design.jd_draft_exists')]);
            }
            $next = $lineage === null ? 1 : (int) JobDescription::query()->where('lineage_key', $lineage)->max('version_no') + 1;
            $jd = new JobDescription([
                'position_id' => $position->getKey(), 'organization_id' => $position->organization_id, 'position_profile_id' => $profile->getKey(),
                'lineage_key' => $lineage ?? (string) Str::uuid(), 'version_no' => $next, 'reference_number' => 'JD-'.$position->job_position_code.'-v'.$next,
                'status' => OrganizationDesignStatus::Draft, 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                'prepared_by' => $actor->getKey(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $jd->save();
            $this->generate($jd, $this->customFromProfile($profile), $data['copy_from'] ?? null);
            $this->audit->record(AuditEventType::JobDescriptionCreated, $actor, $jd, ['version_no' => $next, 'position_profile_id' => $profile->getKey()]);
            $this->audit->transition($actor, $jd, null, OrganizationDesignStatus::Draft->value);

            return $jd;
        });
    }

    /** A new draft from the published document: custom sections copied, derived ones regenerated. */
    public function newVersion(User $actor, JobDescription $published, array $data): JobDescription
    {
        if ($published->status !== OrganizationDesignStatus::Published) {
            throw ValidationException::withMessages(['status' => __('organization-design.jd_version_from_published_only')]);
        }

        return $this->createDraft($actor, $published->position, [...$data, 'copy_from' => $published]);
    }

    public function regenerate(User $actor, JobDescription $jd): JobDescription
    {
        $this->assertDraft($jd);
        DB::transaction(function () use ($actor, $jd): void {
            $this->generate($jd, [], null);
            $this->audit->record(AuditEventType::JobDescriptionRegenerated, $actor, $jd, ['generated_at' => now()->toISOString()]);
        });

        return $jd;
    }

    /** @param array<string, mixed> $data */
    public function updateSection(User $actor, JobDescription $jd, string $key, array $data): JobDescriptionSection
    {
        $this->assertDraft($jd);
        $section = $jd->sections()->where('section_key', $key)->first() ?? abort(404);
        if (! $section->isCustom()) {
            throw ValidationException::withMessages(['section' => __('organization-design.jd_section_derived')]);
        }
        $old = $section->only(['content_en', 'content_am']);
        $section->fill(['content_en' => $data['content_en'] ?? null, 'content_am' => $data['content_am'] ?? null])->save();
        $jd->forceFill(['updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::JobDescriptionSectionUpdated, $actor, $jd, ['section' => $key, ...$section->only(['content_en', 'content_am'])], $old);

        return $section;
    }

    public function submit(User $actor, JobDescription $jd): JobDescription
    {
        $this->assertDraft($jd);
        $this->assertGate($jd);
        $jd->forceFill(['status' => OrganizationDesignStatus::UnderReview, 'submitted_at' => now(), 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $jd, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::UnderReview->value);

        return $jd;
    }

    public function returnToDraft(User $actor, JobDescription $jd, ?string $reason): JobDescription
    {
        if ($jd->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.jd_not_under_review')]);
        }
        $jd->forceFill(['status' => OrganizationDesignStatus::Draft, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_comment' => $reason, 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $jd, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Draft->value, $reason);

        return $jd;
    }

    public function approve(User $actor, JobDescription $jd): JobDescription
    {
        if ($jd->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.jd_not_under_review')]);
        }
        if ($jd->prepared_by !== null && (int) $jd->prepared_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.jd_approve_same_person')]);
        }
        $this->assertGate($jd);
        $jd->forceFill(['status' => OrganizationDesignStatus::Approved, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::JobDescriptionApproved, $actor, $jd, ['version_no' => $jd->version_no]);
        $this->audit->transition($actor, $jd, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Approved->value);

        return $jd;
    }

    /** Publish: supersede the version in force and record the document on the position's current assignments. */
    public function publish(User $actor, JobDescription $jd): JobDescription
    {
        if ($jd->status !== OrganizationDesignStatus::Approved) {
            throw ValidationException::withMessages(['status' => __('organization-design.jd_not_approved')]);
        }

        return DB::transaction(function () use ($actor, $jd): JobDescription {
            $inForce = JobDescription::query()->where('lineage_key', $jd->lineage_key)->where('status', OrganizationDesignStatus::Published)->lockForUpdate()->first();
            $from = Carbon::parse($jd->effective_from);
            if ($inForce !== null && $from->lte(Carbon::parse($inForce->effective_from))) {
                throw ValidationException::withMessages(['effective_from' => __('organization-design.jd_version_must_start_later')]);
            }
            if ($inForce !== null) {
                $inForce->forceFill(['status' => OrganizationDesignStatus::Superseded, 'effective_to' => $from->copy()->subDay()->toDateString(), 'updated_by' => $actor->getKey()])->save();
                $this->audit->transition($actor, $inForce, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value);
            }
            $jd->forceFill(['status' => OrganizationDesignStatus::Published, 'published_by' => $actor->getKey(), 'published_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $stamped = EmployeeAssignment::query()->where('position_id', $jd->position_id)->where('is_current', true)->update(['job_description_id' => $jd->getKey()]);
            $this->audit->record(AuditEventType::JobDescriptionPublished, $actor, $jd, ['version_no' => $jd->version_no, 'assignments_stamped' => $stamped]);
            $this->audit->transition($actor, $jd, OrganizationDesignStatus::Approved->value, OrganizationDesignStatus::Published->value);

            return $jd;
        });
    }

    public function cancel(User $actor, JobDescription $jd, ?string $reason = null): JobDescription
    {
        if (! in_array($jd->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true)) {
            throw ValidationException::withMessages(['status' => __('organization-design.jd_not_cancellable')]);
        }
        $from = $jd->status->value;
        $jd->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $jd, $from, OrganizationDesignStatus::Cancelled->value, $reason);

        return $jd;
    }

    /** The job description in force for a position on a date. */
    public function inForceFor(Position $position, ?string $date = null): ?JobDescription
    {
        $on = $date ?? now()->toDateString();

        return JobDescription::query()->where('position_id', $position->getKey())
            ->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
            ->whereDate('effective_from', '<=', $on)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('version_no')->first();
    }

    // ── Gate ─────────────────────────────────────────────────────────────

    /** @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string} */
    public function validate(JobDescription $jd): array
    {
        $findings = [];
        $subject = ['type' => 'job_description', 'id' => $jd->id, 'code' => $jd->reference_number, 'name' => $jd->position?->title_en];
        $sections = $jd->sections()->get()->keyBy('section_key');
        $duties = $sections->get('duties')?->payload ?? [];
        $dutyCount = count($duties['tasks'] ?? []) + count($duties['activities'] ?? []) + count($duties['processes'] ?? []) + count($duties['services'] ?? []) + count($duties['position_services'] ?? []);
        if ($dutyCount === 0) {
            $findings[] = $this->finding('error', 'jd_without_duties', $subject);
        }
        if (! in_array($jd->profile?->status, [OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded], true)) {
            $findings[] = $this->finding('error', 'jd_profile_not_published', $subject);
        }
        if (blank($sections->get('purpose')?->content_en) && blank($sections->get('purpose')?->content_am)) {
            $findings[] = $this->finding('warning', 'jd_without_purpose', $subject);
        }
        if (count($sections->get('competencies')?->payload ?? []) === 0) {
            $findings[] = $this->finding('warning', 'jd_without_competencies', $subject);
        }
        if (count($sections->get('qualifications')?->payload ?? []) === 0) {
            $findings[] = $this->finding('warning', 'jd_without_qualifications', $subject);
        }
        $findings[] = $this->finding('info', 'jd_sections', $subject, ['count' => $sections->count()]);
        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($findings, fn ($a, $b) => [$order[$a['severity']], $a['code']] <=> [$order[$b['severity']], $b['code']]);
        $counts = collect($findings)->countBy('severity');

        return ['ok' => ($counts['error'] ?? 0) === 0, 'summary' => ['errors' => $counts['error'] ?? 0, 'warnings' => $counts['warning'] ?? 0, 'infos' => $counts['info'] ?? 0], 'findings' => $findings, 'generated_at' => now()->toISOString()];
    }

    // ── Generation ───────────────────────────────────────────────────────

    /**
     * Write every section: derived ones from the masters, custom ones from
     * $customDefaults (new document), $copyFrom (new version) or what is
     * already there (regeneration).
     *
     * @param  array<string, array{en: string|null, am: string|null}>  $customDefaults
     */
    private function generate(JobDescription $jd, array $customDefaults, ?JobDescription $copyFrom): void
    {
        $position = $jd->position()->with(['organization:id,code,name_en,name_am', 'organizationUnit:id,code,name_en,name_am', 'occupation:id,code,isco_code,name_en,name_am'])->first();
        $profile = $jd->profile()->with('reportsTo:id,job_position_code,title_en,title_am')->first();
        $existing = $jd->sections()->get()->keyBy('section_key');
        $copied = $copyFrom?->sections()->get()->keyBy('section_key');
        $derived = $this->derivedPayloads($position, $profile);

        $sequence = 0;
        foreach (self::SECTIONS as $key => $source) {
            $sequence++;
            $attributes = ['source' => $source, 'sequence' => $sequence, 'title_en' => __('organization-design.sections.'.$key, [], 'en'), 'title_am' => __('organization-design.sections.'.$key, [], 'am')];
            if ($source === 'derived') {
                [$payload, $en, $am] = $derived[$key];
                $attributes += ['payload' => $payload, 'content_en' => $en, 'content_am' => $am];
            } else {
                $current = $existing->get($key) ?? $copied?->get($key);
                $attributes += [
                    'content_en' => $current?->content_en ?? $customDefaults[$key]['en'] ?? null,
                    'content_am' => $current?->content_am ?? $customDefaults[$key]['am'] ?? null,
                    'payload' => null,
                ];
            }
            JobDescriptionSection::query()->updateOrCreate(['job_description_id' => $jd->getKey(), 'section_key' => $key], $attributes);
        }
        $jd->forceFill(['generated_at' => now()])->save();
    }

    /** @return array<string, array{en: string|null, am: string|null}> */
    private function customFromProfile(PositionProfile $profile): array
    {
        return [
            'purpose' => ['en' => $profile->purpose_en, 'am' => $profile->purpose_am],
            'working_conditions' => ['en' => $profile->working_conditions_en, 'am' => $profile->working_conditions_am],
            'authorities' => ['en' => $profile->authorities_en, 'am' => $profile->authorities_am],
            'relationships' => ['en' => $profile->relationships_en, 'am' => $profile->relationships_am],
        ];
    }

    /** @return array<string, array{0: mixed, 1: string|null, 2: string|null}> */
    private function derivedPayloads(Position $position, PositionProfile $profile): array
    {
        $links = PositionWorkLink::query()->where('position_id', $position->getKey())->orderBy('sequence')->get();
        $objects = [];
        foreach (['service', 'process', 'activity', 'task'] as $type) {
            $ids = $links->where('object_type', $type)->pluck('object_id');
            $model = PositionWorkLink::OBJECT_MODELS[$type];
            $objects[$type] = $ids->isEmpty() ? collect() : $model::query()->whereIn('id', $ids)->get()->keyBy('id');
        }
        $primary = $links->where('is_primary', true)->pluck('object_id')->flip();
        $line = fn (string $code, ?string $name, ?string $extra = null) => trim($code.' · '.($name ?? '')).($extra ? ' — '.$extra : '');

        $tasks = $links->where('object_type', 'task')->map(fn ($l) => $objects['task']->get($l->object_id))->filter()->sortBy('code')->values();
        $activities = $links->where('object_type', 'activity')->map(fn ($l) => $objects['activity']->get($l->object_id))->filter()->sortBy('code')->values();
        $processes = $links->where('object_type', 'process')->map(fn ($l) => $objects['process']->get($l->object_id))->filter()->sortBy('code')->values();
        $services = $links->where('object_type', 'service')->map(fn ($l) => $objects['service']->get($l->object_id))->filter()->sortBy('code')->values();
        $positionServices = PositionService::query()->where('position_id', $position->getKey())->where('is_active', true)->orderBy('service_no')->get(['id', 'service_no', 'name_en', 'name_am']);
        $duties = [
            'services' => $services->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name_en' => $s->name_en, 'name_am' => $s->name_am])->all(),
            'processes' => $processes->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name_en' => $p->name_en, 'name_am' => $p->name_am])->all(),
            'activities' => $activities->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name_en' => $a->name_en, 'name_am' => $a->name_am])->all(),
            'tasks' => $tasks->map(fn (ProcessTask $t) => ['id' => $t->id, 'code' => $t->code, 'name_en' => $t->name_en, 'name_am' => $t->name_am, 'required_output' => $t->required_output, 'is_primary' => $primary->has($t->id)])->all(),
            'position_services' => $positionServices->map(fn ($s) => ['id' => $s->id, 'service_no' => $s->service_no, 'name_en' => $s->name_en, 'name_am' => $s->name_am])->all(),
        ];
        $dutiesEn = implode("\n", [
            ...$services->map(fn ($s) => $line($s->code, $s->name_en))->all(), ...$processes->map(fn ($p) => $line($p->code, $p->name_en))->all(),
            ...$activities->map(fn ($a) => $line($a->code, $a->name_en))->all(), ...$tasks->map(fn ($t) => $line($t->code, $t->name_en, $t->required_output))->all(),
            ...$positionServices->map(fn ($s) => $line((string) $s->service_no, $s->name_en))->all(),
        ]);
        $dutiesAm = implode("\n", [
            ...$services->map(fn ($s) => $line($s->code, $s->name_am ?? $s->name_en))->all(), ...$processes->map(fn ($p) => $line($p->code, $p->name_am ?? $p->name_en))->all(),
            ...$activities->map(fn ($a) => $line($a->code, $a->name_am ?? $a->name_en))->all(), ...$tasks->map(fn ($t) => $line($t->code, $t->name_am ?? $t->name_en, $t->required_output))->all(),
            ...$positionServices->map(fn ($s) => $line((string) $s->service_no, $s->name_am ?? $s->name_en))->all(),
        ]);

        $reporting = [
            'reports_to' => $profile->reportsTo ? $profile->reportsTo->only(['id', 'job_position_code', 'title_en', 'title_am']) : null,
            'unit' => $position->organizationUnit?->only(['id', 'code', 'name_en', 'name_am']),
            'organization' => $position->organization?->only(['id', 'code', 'name_en', 'name_am']),
            'grade_level' => $position->grade_level,
        ];
        $reportingEn = implode("\n", array_filter([
            $position->organization ? 'Organization: '.$position->organization->name_en : null,
            $position->organizationUnit ? 'Unit: '.$position->organizationUnit->code.' · '.$position->organizationUnit->name_en : null,
            $profile->reportsTo ? 'Reports to: '.$profile->reportsTo->job_position_code.' · '.$profile->reportsTo->title_en : null,
            $position->grade_level ? 'Grade: '.$position->grade_level : null,
        ]));
        $reportingAm = implode("\n", array_filter([
            $position->organization ? 'ተቋም፡ '.($position->organization->name_am ?? $position->organization->name_en) : null,
            $position->organizationUnit ? 'ክፍል፡ '.$position->organizationUnit->code.' · '.($position->organizationUnit->name_am ?? $position->organizationUnit->name_en) : null,
            $profile->reportsTo ? 'ሪፖርት የሚያደርግለት፡ '.$profile->reportsTo->job_position_code.' · '.($profile->reportsTo->title_am ?? $profile->reportsTo->title_en) : null,
            $position->grade_level ? 'ደረጃ፡ '.$position->grade_level : null,
        ]));

        $occupation = $position->occupation ? $position->occupation->only(['id', 'code', 'isco_code', 'name_en', 'name_am']) : null;
        $occupationEn = $occupation ? trim(($occupation['isco_code'] ?? $occupation['code']).' · '.($occupation['name_en'] ?? '')) : null;
        $occupationAm = $occupation ? trim(($occupation['isco_code'] ?? $occupation['code']).' · '.($occupation['name_am'] ?? $occupation['name_en'] ?? '')) : null;

        $competencies = PositionCompetencyRequirement::query()->where('position_id', $position->getKey())->with(['competency:id,code,name_en,name_am', 'level:id,code,name_en,name_am,rank'])->orderBy('sequence')->get()
            ->map(fn ($r) => ['competency' => $r->competency?->only(['id', 'code', 'name_en', 'name_am']), 'level' => $r->level?->only(['id', 'code', 'name_en', 'name_am', 'rank']), 'is_mandatory' => $r->is_mandatory, 'source' => $r->source])->values()->all();
        $competenciesEn = implode("\n", array_map(fn ($c) => ($c['competency']['name_en'] ?? '').' — '.($c['level']['name_en'] ?? '').($c['is_mandatory'] ? '' : ' (optional)'), $competencies));
        $competenciesAm = implode("\n", array_map(fn ($c) => ($c['competency']['name_am'] ?? $c['competency']['name_en'] ?? '').' — '.($c['level']['name_am'] ?? $c['level']['name_en'] ?? '').($c['is_mandatory'] ? '' : ' (አማራጭ)'), $competencies));

        $qualifications = PositionQualificationRequirement::query()->where('position_id', $position->getKey())->with('educationLevel:id,code,name_en,name_am,rank')->orderBy('sequence')->get()
            ->map(fn ($r) => ['education_level' => $r->educationLevel?->only(['id', 'code', 'name_en', 'name_am', 'rank']), 'field_of_study' => $r->field_of_study, 'certification' => $r->certification, 'license' => $r->license, 'experience_years' => $r->experience_years, 'is_mandatory' => $r->is_mandatory, 'source' => $r->source])->values()->all();
        $qualLine = fn ($q, string $lang) => implode(' · ', array_filter([$q['education_level'][$lang === 'am' ? 'name_am' : 'name_en'] ?? $q['education_level']['name_en'] ?? null, $q['field_of_study'], $q['certification'], $q['license'], $q['experience_years'] ? $q['experience_years'].($lang === 'am' ? ' ዓመት ልምድ' : ' years of experience') : null]));
        $qualificationsEn = implode("\n", array_map(fn ($q) => $qualLine($q, 'en'), $qualifications));
        $qualificationsAm = implode("\n", array_map(fn ($q) => $qualLine($q, 'am'), $qualifications));

        $standards = PositionServiceTaskStandard::query()->inForceOn(now()->toDateString())
            ->where(fn ($q) => $q->whereIn('master_task_id', $tasks->pluck('id'))->orWhereIn('activity_id', $activities->pluck('id')))
            ->orderByDesc('effective_from')->get()
            ->map(fn (PositionServiceTaskStandard $s) => [
                'task_code' => $s->master_task_id ? ($objects['task']->get($s->master_task_id)?->code) : ($objects['activity']->get($s->activity_id)?->code),
                'version_no' => $s->version_no, 'planned_quantity' => $s->planned_quantity === null ? null : (string) $s->planned_quantity, 'quantity_unit' => $s->quantity_unit,
                'planned_time_minutes' => $s->planned_time_minutes === null ? null : (string) $s->planned_time_minutes, 'planned_quality' => $s->planned_quality === null ? null : (string) $s->planned_quality, 'quality_unit' => $s->quality_unit,
                'effective_from' => $s->effective_from?->toDateString(),
            ])->values()->all();
        $standardLine = fn ($s, string $lang) => ($s['task_code'] ?? '').' · '.implode(', ', array_filter([
            $s['planned_quantity'] !== null ? ($lang === 'am' ? 'መጠን ' : 'quantity ').rtrim(rtrim($s['planned_quantity'], '0'), '.').' '.($s['quantity_unit'] ?? '') : null,
            $s['planned_time_minutes'] !== null ? ($lang === 'am' ? 'ጊዜ ' : 'time ').rtrim(rtrim($s['planned_time_minutes'], '0'), '.').($lang === 'am' ? ' ደቂቃ' : ' min') : null,
            $s['planned_quality'] !== null ? ($lang === 'am' ? 'ጥራት ' : 'quality ').rtrim(rtrim($s['planned_quality'], '0'), '.').' '.($s['quality_unit'] ?? '') : null,
        ]));

        return [
            'reporting' => [$reporting, $reportingEn ?: null, $reportingAm ?: null],
            'duties' => [$duties, $dutiesEn ?: null, $dutiesAm ?: null],
            'occupation' => [$occupation, $occupationEn, $occupationAm],
            'qualifications' => [$qualifications, $qualificationsEn ?: null, $qualificationsAm ?: null],
            'competencies' => [$competencies, $competenciesEn ?: null, $competenciesAm ?: null],
            'standards' => [$standards, implode("\n", array_map(fn ($s) => $standardLine($s, 'en'), $standards)) ?: null, implode("\n", array_map(fn ($s) => $standardLine($s, 'am'), $standards)) ?: null],
        ];
    }

    private function assertGate(JobDescription $jd): void
    {
        $report = $this->validate($jd);
        if (! $report['ok']) {
            throw ValidationException::withMessages(['validation' => collect($report['findings'])->where('severity', 'error')->pluck('message')->all()]);
        }
    }

    private function assertDraft(JobDescription $jd): void
    {
        if (! $jd->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.jd_not_draft')]);
        }
    }

    /** @return array<string, mixed> */
    private function finding(string $severity, string $code, array $subject, array $params = []): array
    {
        $label = trim(implode(' ', array_filter([$subject['code'] ?? null, $subject['name'] ?? null])));

        return ['severity' => $severity, 'code' => $code, 'message' => __('organization-design.validation.'.$code, ['subject' => $label !== '' ? $label : '—', ...$params]), 'subject' => $subject, 'params' => $params];
    }
}
